<?php

namespace App\Services\Pac;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PAC CFDI Express — https://api.cfdi.express (OpenAPI en /openapi.json).
 *
 * UNA cuenta nuestra (API key sk_test_/sk_live_ en config). Cada emisor
 * (tenant o el propio EventPOS) es un "merchant" dentro de esa cuenta,
 * identificado por RFC. El saldo de timbres es prepago y central.
 *
 * - El modo (test/live) es global: CFDI_EXPRESS_MODE. El tenant no lo elige.
 * - pac_id se guarda como "{modo}:{invoiceId}" para que descargas y
 *   cancelaciones usen la key correcta aunque cambie el modo del servidor.
 * - Los impuestos los calcula CFDI Express; se mandan precios con IVA incluido.
 * - XML/PDF se generan de forma asíncrona y se sirven por URL firmada (15 min).
 */
class CfdiExpressPac implements PacContract
{
    private const MERCHANT_CACHE_TTL = 86400;

    public function key(): string
    {
        return 'cfdi_express';
    }

    // ─── PacContract ──────────────────────────────────────────────────────────

    public function setup(array $ctx): array
    {
        $merchant = $this->merchant($ctx, createIfMissing: true);
        $merchant = $this->syncMerchant($merchant, $ctx);

        return ['org_id' => $merchant['id'], 'merchant_id' => $merchant['id']];
    }

    public function subirCsd(array $ctx, string $cerBase64, string $keyBase64, string $password): void
    {
        $mode       = $this->mode();
        $merchantId = $this->merchantId($ctx, createIfMissing: true);
        $payload    = ['cer' => $cerBase64, 'key' => $keyBase64, 'password' => $password];

        $resp = $this->send(fn () => $this->http($mode)->post("/merchants/{$merchantId}/csd", $payload));

        // Ya tenía CSD → reemplazarlo.
        if ($resp->status() === 409) {
            $resp = $this->send(fn () => $this->http($mode)->put("/merchants/{$merchantId}/csd", $payload));
        }

        $this->throwIfError($resp, 'Error al cargar el CSD en CFDI Express');

        // El SAT exige que el nombre del emisor coincida con el del certificado
        // (sin régimen societario en personas morales — CFDI40139).
        $nombreSat = $this->extractNombreFromCer($cerBase64);
        if ($nombreSat) {
            $patch = $this->send(fn () => $this->http($mode)->patch("/merchants/{$merchantId}", [
                'legalName' => mb_substr($this->razonSocialCfdi($nombreSat, (string) ($ctx['rfc'] ?? '')), 0, 300),
            ]));
            if (!$patch->successful()) {
                Log::warning('CFDI Express: no se pudo actualizar legalName del merchant', [
                    'merchant_id' => $merchantId,
                    'status'      => $patch->status(),
                    'body'        => $patch->json(),
                ]);
            }
        }
    }

    public function crearFactura(array $ctx, array $invoice): array
    {
        $mode     = $this->mode();
        $merchant = $this->merchant($ctx, createIfMissing: false);
        $payload  = $this->buildPayload($merchant, $invoice, $ctx);

        // Mismo cuerpo → misma key: un reintento tras timeout (sin respuesta) no timbra dos veces.
        // Si CFDI Express SÍ respondió con error, el siguiente intento usa otra key: si no,
        // replicaría el error guardado aunque la causa (CSD, PAC caído) ya se haya corregido.
        $hash         = sha1(json_encode($payload));
        $failCacheKey = "cfdi_express_idem_fail_{$hash}";
        $attempt      = (int) Cache::get($failCacheKey, 0);
        $idempotencyKey = "cfdi-{$hash}" . ($attempt > 0 ? "-r{$attempt}" : '');

        $resp = $this->send(fn () => $this->http($mode, 60)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/invoices', $payload));

        if ($resp->status() === 404) {
            // Merchant borrado/archivado del lado de CFDI Express: refrescar caché.
            $this->forgetMerchant($ctx);
        }

        // 409 = mismo key aún en vuelo: NO rotar, el reintento debe deduplicarse.
        if (!$resp->successful() && $resp->status() !== 409) {
            Cache::put($failCacheKey, $attempt + 1, 86400);
        }

        $this->throwIfError($resp, 'Error al timbrar en CFDI Express');

        $data = $resp->json() ?? [];

        // 202 = sigue timbrando bajo carga extrema → consultar hasta resolver.
        if (($data['status'] ?? null) === 'stamping') {
            $data = $this->waitForStamp($mode, (string) $data['id']);
        }

        if (($data['status'] ?? null) !== 'stamped' || empty($data['uuid'])) {
            Cache::put($failCacheKey, $attempt + 1, 86400);
            throw new \Exception('CFDI Express no pudo timbrar la factura. Intenta de nuevo en unos minutos.');
        }

        $pacId = $this->encodePacId($mode, (string) $data['id']);

        // El XML se genera en segundo plano; si no está listo pronto se descarga después.
        $xml = null;
        try {
            $xml = $this->downloadFile($mode, (string) $data['id'], 'xmlUrl', 15);
        } catch (\Throwable $e) {
            Log::info('CFDI Express: XML aún no disponible tras timbrar', ['pac_id' => $pacId]);
        }

        return [
            'uuid'   => $data['uuid'],
            'pac_id' => $pacId,
            'xml'    => $xml,
        ];
    }

    public function descargarXml(array $ctx, string $pacId): string
    {
        [$mode, $invoiceId] = $this->decodePacId($pacId);

        return $this->downloadFile($mode, $invoiceId, 'xmlUrl');
    }

    public function descargarPdf(array $ctx, string $pacId): string
    {
        [$mode, $invoiceId] = $this->decodePacId($pacId);

        return $this->downloadFile($mode, $invoiceId, 'pdfUrl');
    }

    public function cancelarFactura(array $ctx, string $pacId, string $motivo, ?string $uuidRelacionado): array
    {
        [$mode, $invoiceId] = $this->decodePacId($pacId);

        if (!in_array($motivo, ['01', '02', '03', '04'], true)) {
            throw new \Exception('Motivo de cancelación inválido.');
        }
        if ($motivo === '01' && !$uuidRelacionado) {
            throw new \Exception('El motivo 01 requiere el UUID de la factura que sustituye a esta.');
        }

        $body = ['motivo' => $motivo];
        if ($motivo === '01') {
            $body['folioSustitucion'] = $uuidRelacionado;
        }

        $resp = $this->send(fn () => $this->http($mode, 60)
            ->withHeaders(['Idempotency-Key' => "cancel-{$invoiceId}-{$motivo}"])
            ->post("/invoices/{$invoiceId}/cancel", $body));

        $this->throwIfError($resp, 'Error al cancelar el CFDI en CFDI Express');

        return $resp->json() ?? [];
    }

    public function test(array $ctx): bool
    {
        $mode       = $this->mode();
        $merchantId = $this->merchantId($ctx, createIfMissing: false);

        $resp = $this->send(fn () => $this->http($mode)->get("/merchants/{$merchantId}/csd"));
        $this->throwIfError($resp, 'No se pudo consultar el CSD en CFDI Express');

        $csd = $resp->json();
        if (empty($csd)) {
            throw new \Exception('Falta subir el CSD (.cer y .key) del emisor.');
        }
        if (($csd['status'] ?? null) === 'expired') {
            throw new \Exception('El CSD del emisor está vencido. Sube uno vigente.');
        }

        return true;
    }

    /** Extrae el nombre (CN) del certificado — el SAT exige que coincida con el emisor. */
    public function extractNombreFromCer(string $cerBase64): ?string
    {
        try {
            $tmp = tempnam(sys_get_temp_dir(), 'cer_');
            file_put_contents($tmp, base64_decode($cerBase64));
            $output = [];
            exec('openssl x509 -inform DER -in ' . escapeshellarg($tmp) . ' -noout -subject -nameopt utf8,sep_comma_plus 2>&1', $output);
            @unlink($tmp);

            if (preg_match('/CN\s*=\s*([^,]+)/i', implode('', $output), $m)) {
                return trim($m[1]);
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ─── Saldo (no es parte del contrato; lo usa superadmin) ─────────────────

    public function balance(?string $mode = null): array
    {
        $mode ??= $this->mode();
        $resp = $this->send(fn () => $this->http($mode)->get('/balance'));
        $this->throwIfError($resp, 'No se pudo consultar el saldo de CFDI Express');

        return $resp->json() ?? [];
    }

    // ─── Merchants ────────────────────────────────────────────────────────────

    private function merchantId(array $ctx, bool $createIfMissing): string
    {
        return $this->merchant($ctx, $createIfMissing)['id'];
    }

    /** @return array{id:string, zip:?string, regimenFiscal:?string, serie:?string} */
    private function merchant(array $ctx, bool $createIfMissing): array
    {
        $mode = $this->mode();
        $rfc  = strtoupper(trim($ctx['rfc'] ?? ''));

        if ($rfc === '') {
            throw new \Exception('Configura el RFC del emisor antes de facturar.');
        }

        $cacheKey = $this->merchantCacheKey($mode, $rfc);
        $cached   = Cache::get($cacheKey);
        if (is_array($cached) && !empty($cached['id'])) {
            return $cached;
        }

        $merchant = $this->findMerchantByRfc($mode, $rfc);

        if (!$merchant && $createIfMissing) {
            $merchant = $this->createMerchant($mode, $rfc, $ctx);
        }

        if (!$merchant) {
            throw new \Exception('El emisor no está registrado en el servicio de facturación. Ve a Configuración → Facturación y activa la facturación.');
        }

        Cache::put($cacheKey, $merchant, self::MERCHANT_CACHE_TTL);

        return $merchant;
    }

    private function summarizeMerchant(array $m): array
    {
        return [
            'id'            => (string) $m['id'],
            'zip'           => $m['zip'] ?? null,
            'regimenFiscal' => $m['regimenFiscal'] ?? null,
            'serie'         => $m['serie'] ?? null,
        ];
    }

    /** Sincroniza CP/régimen/serie del merchant con la configuración local (el RFC no se toca). */
    private function syncMerchant(array $merchant, array $ctx): array
    {
        $patch   = [];
        $zip     = trim((string) ($ctx['codigo_postal'] ?? ''));
        $regimen = trim((string) ($ctx['regimen_fiscal'] ?? ''));
        $serie   = mb_substr(trim((string) ($ctx['serie'] ?? '')), 0, 10);

        if (preg_match('/^\d{5}$/', $zip) && $zip !== ($merchant['zip'] ?? null)) {
            $patch['zip'] = $zip;
        }
        if (preg_match('/^\d{3}$/', $regimen) && $regimen !== ($merchant['regimenFiscal'] ?? null)) {
            $patch['regimenFiscal'] = $regimen;
        }
        if ($serie !== '' && $serie !== ($merchant['serie'] ?? null)) {
            $patch['serie'] = $serie;
        }

        if (!$patch) {
            return $merchant;
        }

        $mode = $this->mode();
        $resp = $this->send(fn () => $this->http($mode)->patch("/merchants/{$merchant['id']}", $patch));
        $this->throwIfError($resp, 'No se pudieron actualizar los datos del emisor en CFDI Express');

        $merchant = $this->summarizeMerchant($resp->json() ?: array_merge($merchant, $patch));
        Cache::put($this->merchantCacheKey($mode, strtoupper(trim($ctx['rfc']))), $merchant, self::MERCHANT_CACHE_TTL);

        return $merchant;
    }

    private function findMerchantByRfc(string $mode, string $rfc): ?array
    {
        $cursor = null;

        // Paginación por cursor; tope defensivo de 50 páginas (5,000 merchants).
        for ($page = 0; $page < 50; $page++) {
            $query = ['limit' => 100];
            if ($cursor) {
                $query['starting_after'] = $cursor;
            }

            $resp = $this->send(fn () => $this->http($mode)->get('/merchants', $query));
            $this->throwIfError($resp, 'No se pudo consultar los emisores en CFDI Express');

            $items = $resp->json('data') ?? [];
            foreach ($items as $merchant) {
                if (strtoupper($merchant['rfc'] ?? '') === $rfc && empty($merchant['archivedAt'])) {
                    return $this->summarizeMerchant($merchant);
                }
            }

            if (!($resp->json('has_more') ?? false) || empty($items)) {
                return null;
            }

            $cursor = end($items)['id'] ?? null;
        }

        return null;
    }

    private function createMerchant(string $mode, string $rfc, array $ctx): array
    {
        $legalName = trim((string) (($ctx['nombre_sat'] ?? '') ?: ($ctx['nombre'] ?? '')));
        $regimen   = trim((string) ($ctx['regimen_fiscal'] ?? ''));
        $zip       = trim((string) ($ctx['codigo_postal'] ?? ''));

        if ($legalName === '') {
            throw new \Exception('Captura la razón social del emisor antes de activar la facturación.');
        }
        if (!preg_match('/^\d{3}$/', $regimen)) {
            throw new \Exception('Selecciona el régimen fiscal del emisor antes de activar la facturación.');
        }
        if (!preg_match('/^\d{5}$/', $zip)) {
            throw new \Exception('El código postal del emisor debe tener 5 dígitos.');
        }

        $payload = [
            'rfc'           => $rfc,
            'legalName'     => mb_substr($this->razonSocialCfdi($legalName, $rfc), 0, 300),
            'regimenFiscal' => $regimen,
            'zip'           => $zip,
        ];

        $serie = trim((string) ($ctx['serie'] ?? ''));
        if ($serie !== '') {
            $payload['serie'] = mb_substr($serie, 0, 10);
        }

        $resp = $this->send(fn () => $this->http($mode)->post('/merchants', $payload));
        $this->throwIfError($resp, 'Error al registrar el emisor en CFDI Express');

        return $this->summarizeMerchant(array_merge($payload, $resp->json() ?? []));
    }

    /**
     * CFDI 4.0 exige la razón social SIN régimen societario (CFDI40139): el CSD de una
     * persona moral trae "ESCUELA KEMPER URGATE SA DE CV" y el CFDI debe decir
     * "ESCUELA KEMPER URGATE". Personas físicas (RFC de 13) no se tocan.
     */
    public function razonSocialCfdi(string $nombre, string $rfc): string
    {
        $nombre = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $nombre)));

        if (strlen(strtoupper(trim($rfc))) !== 12) {
            return $nombre;
        }

        $limpio = trim(preg_replace('/\s+/', ' ', str_replace(['.', ','], ' ', $nombre)));
        $sinRegimen = preg_replace(
            '/\s+(S\s?A\s?P\s?I|S\s?A\s?B|S\s?A\s?S|S\s?A|S\s?DE\s?R\s?L(\s?MI)?|S\s?P\s?R\s?DE\s?R\s?L|S\s?EN\s?C(\s?POR\s?A)?|S\s?C\s?L|S\s?C\s?P|S\s?C|A\s?C|I\s?A\s?P)(\s+DE\s+C\s?V)?$/u',
            '',
            $limpio
        );

        return trim($sinRegimen) !== '' ? trim($sinRegimen) : $nombre;
    }

    private function forgetMerchant(array $ctx): void
    {
        $rfc = strtoupper(trim($ctx['rfc'] ?? ''));
        if ($rfc !== '') {
            Cache::forget($this->merchantCacheKey($this->mode(), $rfc));
        }
    }

    private function merchantCacheKey(string $mode, string $rfc): string
    {
        return "cfdi_express_merchant_{$mode}_{$rfc}";
    }

    // ─── Facturas ─────────────────────────────────────────────────────────────

    private function buildPayload(array $merchant, array $invoice, array $ctx): array
    {
        $merchantId = $merchant['id'];
        $tasa       = $this->normalizeIvaRate((float) ($invoice['tasa_iva'] ?? 0.16));
        $receptor   = $invoice['receptor'] ?? [];
        // Lugar de expedición real = CP registrado en el merchant (no el de la config local).
        $emisorCp   = $merchant['zip'] ?: ($invoice['emisor']['codigo_postal'] ?? ($ctx['codigo_postal'] ?? ''));
        $esPublico = !empty($receptor['es_publico']);

        $items = [];
        foreach ($invoice['items'] as $it) {
            $precio = round((float) $it['precio_con_iva'], 6);
            if ($precio <= 0) {
                throw new \Exception('No se puede facturar un concepto con precio $0: ' . ($it['descripcion'] ?? ''));
            }

            $item = [
                'productCode' => $it['clave_sat'] ?: '01010101',
                'unitCode'    => $it['clave_unidad'] ?: 'H87',
                'unit'        => mb_substr($it['unidad'] ?? 'Pieza', 0, 20),
                'description' => mb_substr(mb_strtoupper($it['descripcion']), 0, 1000),
                'quantity'    => (float) $it['cantidad'],
                'unitPrice'   => $precio,
                'taxable'     => true,
                'ivaRate'     => $tasa,
            ];

            $codigo = trim(str_replace('|', '', (string) ($it['codigo'] ?? '')));
            if ($codigo !== '') {
                $item['noIdentificacion'] = mb_substr($codigo, 0, 100);
            }

            $items[] = $item;
        }

        $payload = [
            'merchantId'       => $merchantId,
            'metodoPago'       => $invoice['metodo_pago'] ?? 'PUE',
            'formaPago'        => $invoice['forma_pago'] ?? '99',
            'pricesIncludeTax' => true,
            'items'            => $items,
        ];

        if ($esPublico) {
            // CFDI 4.0: público en general exige DomicilioFiscalReceptor = LugarExpedicion
            // e InformacionGlobal.
            $payload['usoCfdi']  = 'S01';
            $payload['receiver'] = [
                'rfc'           => 'XAXX010101000',
                'name'          => 'PUBLICO EN GENERAL',
                'zip'           => $emisorCp,
                'regimenFiscal' => '616',
            ];
            $now = now('America/Mexico_City');
            $payload['informacionGlobal'] = [
                'periodicidad' => '01',
                'meses'        => $now->format('m'),
                'anio'         => (int) $now->format('Y'),
            ];
        } else {
            $payload['usoCfdi']  = $receptor['uso_cfdi'] ?? 'G03';
            $payload['receiver'] = [
                'rfc'           => strtoupper(trim($receptor['rfc'] ?? '')),
                'name'          => mb_substr(mb_strtoupper(trim($receptor['nombre'] ?? '')), 0, 300),
                'zip'           => $receptor['codigo_postal'] ?? '',
                'regimenFiscal' => $receptor['regimen_fiscal'] ?? '',
            ];
        }

        // Folio visible en el CFDI (ej. folio de la venta). El folio interno lo asigna CFDI Express.
        $referencia = trim(str_replace('|', '', (string) ($invoice['referencia'] ?? '')));
        if ($referencia !== '') {
            $payload['folio'] = mb_substr($referencia, 0, 40);
        }

        return $payload;
    }

    private function normalizeIvaRate(float $tasa): float
    {
        // CFDI Express solo acepta 0, 0.08 o 0.16.
        if ($tasa >= 0.12) return 0.16;
        if ($tasa >= 0.04) return 0.08;
        return 0;
    }

    private function waitForStamp(string $mode, string $invoiceId, int $timeoutSeconds = 25): array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            usleep(1_500_000);
            $resp = $this->send(fn () => $this->http($mode)->get("/invoices/{$invoiceId}"));
            $this->throwIfError($resp, 'No se pudo consultar el estado de la factura en CFDI Express');
            $data = $resp->json() ?? [];

            if (in_array($data['status'] ?? null, ['stamped', 'stamp_failed'], true)) {
                return $data;
            }
        } while (microtime(true) < $deadline);

        throw new \Exception('El timbrado está tardando más de lo normal. Revisa la factura en unos minutos antes de reintentar.');
    }

    /** Espera a que los archivos estén listos y descarga el contenido del URL firmado. */
    private function downloadFile(string $mode, string $invoiceId, string $urlField, int $timeoutSeconds = 20): string
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $resp = $this->send(fn () => $this->http($mode)->get("/invoices/{$invoiceId}"));
            $this->throwIfError($resp, 'No se pudo consultar la factura en CFDI Express');

            $files  = $resp->json('files') ?? [];
            $status = $files['status'] ?? 'pending';

            if ($status === 'ready' && !empty($files[$urlField])) {
                $file = $this->send(fn () => Http::timeout(30)->get($files[$urlField]));
                if (!$file->successful()) {
                    throw new \Exception('No se pudo descargar el archivo del CFDI desde CFDI Express.');
                }
                return $file->body();
            }

            if ($status === 'failed') {
                throw new \Exception('CFDI Express no pudo generar los archivos del CFDI.');
            }

            if (microtime(true) >= $deadline) {
                throw new \Exception('Los archivos del CFDI todavía se están generando. Intenta de nuevo en unos segundos.');
            }

            usleep(1_500_000);
        }
    }

    // ─── HTTP ─────────────────────────────────────────────────────────────────

    private function mode(): string
    {
        return config('services.cfdi_express.mode') === 'live' ? 'live' : 'test';
    }

    private function http(string $mode, int $timeout = 30): PendingRequest
    {
        $key = config("services.cfdi_express.{$mode}_key");
        if (!$key) {
            throw new \Exception('CFDI Express: falta CFDI_EXPRESS_' . strtoupper($mode) . '_KEY en el servidor.');
        }

        $base = rtrim((string) config('services.cfdi_express.url', 'https://api.cfdi.express'), '/');

        return Http::withToken($key)
            ->baseUrl($base . '/v1')
            ->acceptJson()
            ->asJson()
            ->timeout($timeout);
    }

    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new \Exception('No se pudo conectar con el servicio de facturación. Intenta de nuevo.');
        }
    }

    private function encodePacId(string $mode, string $invoiceId): string
    {
        return "{$mode}:{$invoiceId}";
    }

    /** @return array{0:string,1:string} [modo, invoiceId] */
    private function decodePacId(string $pacId): array
    {
        if (preg_match('/^(test|live):(.+)$/', $pacId, $m)) {
            return [$m[1], $m[2]];
        }

        return [$this->mode(), $pacId];
    }

    private function throwIfError(Response $response, string $fallback): void
    {
        if ($response->successful()) return;

        $body = $response->json() ?? [];
        Log::error('CFDI Express API error', [
            'status' => $response->status(),
            'body'   => $body,
        ]);

        $code   = $body['code'] ?? $body['type'] ?? null;
        $detail = $body['detail'] ?? $body['message'] ?? $body['title'] ?? null;

        if ($response->status() === 402 || $code === 'insufficient_credits') {
            // El saldo es nuestro (cuenta central), no del tenant.
            Log::critical('CFDI Express: saldo insuficiente — recargar la cuenta central');
            throw new \Exception('El servicio de facturación no está disponible por el momento. Contacta a soporte.');
        }

        if ($response->status() === 409) {
            throw new \Exception('Ya hay un timbrado en proceso para esta factura. Espera unos segundos y revisa antes de reintentar.');
        }

        if ($response->status() === 429) {
            throw new \Exception('Demasiadas solicitudes al servicio de facturación. Intenta de nuevo en un minuto.');
        }

        if ($response->status() === 503) {
            throw new \Exception('El PAC no está disponible en este momento. Intenta de nuevo en unos minutos.');
        }

        $errors = $body['errors'] ?? null;
        if (is_array($errors) && $errors) {
            $first = reset($errors);
            $extra = is_array($first) ? ($first['message'] ?? json_encode($first)) : (string) $first;
            $detail = trim(($detail ? $detail . ': ' : '') . $extra);
        }

        throw new \Exception((string) ($detail ?: $fallback . ' [HTTP ' . $response->status() . ']'));
    }
}
