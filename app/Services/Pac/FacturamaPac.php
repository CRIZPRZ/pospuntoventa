<?php

namespace App\Services\Pac;

use Illuminate\Support\Facades\Http;

/**
 * PAC Facturama — API Multiemisor.
 *
 * UNA sola cuenta nuestra (Basic Auth global desde config/services.php).
 * Cada tenant es un emisor: su CSD se carga bajo su RFC en nuestra cuenta.
 *
 * Diferencia clave vs Facturapi: Facturama NO soporta "precio con IVA
 * incluido"; hay que des-IVAr y mandar el desglose explícito por ítem.
 */
class FacturamaPac implements PacContract
{
    public function key(): string
    {
        return 'facturama';
    }

    private function baseUrl(): string
    {
        return config('services.facturama.sandbox', true)
            ? 'https://apisandbox.facturama.mx'
            : 'https://api.facturama.mx';
    }

    private function http()
    {
        return Http::withBasicAuth(
            config('services.facturama.user'),
            config('services.facturama.password'),
        )->acceptJson()->timeout(25);
    }

    /** HTTP client sin Accept: application/json — para descargar archivos binarios. */
    private function httpFile()
    {
        return Http::withBasicAuth(
            config('services.facturama.user'),
            config('services.facturama.password'),
        )->timeout(25);
    }

    public function setup(array $ctx): array
    {
        // En Facturama el emisor "existe" al cargar su CSD — no hay organización.
        return ['org_id' => null];
    }

    public function subirCsd(array $ctx, string $cerBase64, string $keyBase64, string $password): void
    {
        try {
            $resp = $this->http()->post($this->baseUrl() . '/api-lite/csds', [
                'Rfc'                => strtoupper(trim($ctx['rfc'] ?? '')),
                'Certificate'        => $cerBase64,
                'PrivateKey'         => $keyBase64,
                'PrivateKeyPassword' => $password,
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('No se pudo conectar con Facturama al cargar el CSD. Intenta de nuevo.');
        }

        $this->throwIfError($resp, 'Error al cargar el CSD en Facturama');

        // Guardar nombre SAT del CSD para que el Issuer.Name coincida exactamente.
        $nombreSat = $this->extractNombreFromCer($cerBase64);
        if ($nombreSat && isset($ctx['_save_nombre_callback']) && is_callable($ctx['_save_nombre_callback'])) {
            ($ctx['_save_nombre_callback'])($nombreSat);
        }
    }

    /** Extrae el nombre (CN) del contribuyente del .cer en base64 (formato DER SAT). */
    public function extractNombreFromCer(string $cerBase64): ?string
    {
        try {
            $tmp = tempnam(sys_get_temp_dir(), 'cer_');
            file_put_contents($tmp, base64_decode($cerBase64));
            $output = [];
            exec('openssl x509 -inform DER -in ' . escapeshellarg($tmp) . ' -noout -subject 2>&1', $output);
            @unlink($tmp);

            // subject=CN=XOCHILT CASAS CHAVEZ, ...
            $line = implode('', $output);
            if (preg_match('/CN=([^,]+)/i', $line, $m)) {
                return trim($m[1]);
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function crearFactura(array $ctx, array $invoice): array
    {
        $payload = $this->buildPayload($invoice);

        try {
            $resp = $this->http()->post($this->baseUrl() . '/api-lite/3/cfdis', $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('No se pudo conectar con Facturama (timeout). Intenta de nuevo en unos segundos.');
        }

        $this->throwIfError($resp, 'Error al timbrar en Facturama');

        $data  = $resp->json();
        $id    = $data['Id'] ?? null;
        $uuid  = $data['Complement']['TaxStamp']['Uuid']
            ?? $data['Uuid']
            ?? null;

        $xml = null;
        if ($id) {
            try { $xml = $this->descargarXml($ctx, $id); } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Facturama XML no disponible aún: ' . $e->getMessage());
            }
        }

        return ['uuid' => $uuid, 'pac_id' => $id, 'xml' => $xml];
    }

    public function descargarXml(array $ctx, string $pacId): string
    {
        $resp = $this->httpFile()->get(
            $this->baseUrl() . "/api-lite/cfdi/{$pacId}?type=issued&format=xml"
        );

        if (!$resp->successful()) {
            throw new \Exception('Error al descargar XML de Facturama [HTTP ' . $resp->status() . ']');
        }

        $body = $resp->body();
        if (empty($body)) {
            throw new \Exception('El XML del CFDI no está disponible todavía en Facturama');
        }

        return $body;
    }

    public function descargarPdf(array $ctx, string $pacId): string
    {
        $resp = $this->httpFile()->get(
            $this->baseUrl() . "/api-lite/cfdi/{$pacId}?type=issued&format=pdf"
        );

        if (!$resp->successful()) {
            throw new \Exception('Error al descargar PDF de Facturama [HTTP ' . $resp->status() . ']');
        }

        $body = $resp->body();
        if (empty($body)) {
            throw new \Exception('El PDF del CFDI no está disponible todavía en Facturama');
        }

        return $body;
    }

    public function cancelarFactura(array $ctx, string $pacId, string $motivo, ?string $uuidRelacionado): array
    {
        $query = ['motive' => $motivo, 'type' => 'issued'];
        if ($motivo === '01' && $uuidRelacionado) {
            $query['uuidReplacement'] = $uuidRelacionado;
        }

        $resp = $this->http()->delete($this->baseUrl() . "/api-lite/cfdis/{$pacId}", $query);
        $this->throwIfError($resp, 'Error al cancelar CFDI en Facturama');

        return $resp->json() ?? [];
    }

    public function test(array $ctx): bool
    {
        try {
            $resp = $this->http()->get($this->baseUrl() . '/api-lite/csds');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('No se pudo conectar con Facturama (timeout). Verifica tu conexión.');
        }

        $this->throwIfError($resp, 'No se pudo conectar con Facturama');
        return true;
    }

    // ─── Payload (de-IVA + nodo Issuer) ──────────────────────────────────────

    private function buildPayload(array $invoice): array
    {
        $tasa = (float) $invoice['tasa_iva'];
        $e    = $invoice['emisor'];
        $r    = $invoice['receptor'];

        $items = array_map(function (array $it) use ($tasa) {
            $cant      = (float) $it['cantidad'];
            // Des-IVA: el precio capturado ya incluye IVA.
            $unitBase  = round((float) $it['precio_con_iva'] / (1 + $tasa), 6);
            $subtotal  = round($unitBase * $cant, 2);
            $ivaTotal  = round($subtotal * $tasa, 2);
            $total     = round($subtotal + $ivaTotal, 2);

            return [
                'ProductCode'          => $it['clave_sat'],
                'IdentificationNumber' => $it['codigo'] ?? '',
                'Description'          => mb_strtoupper($it['descripcion']),
                'Unit'                 => $it['unidad'] ?? 'Pieza',
                'UnitCode'             => $it['clave_unidad'],
                'UnitPrice'            => $unitBase,
                'Quantity'             => $cant,
                'Subtotal'             => $subtotal,
                'Discount'             => 0,
                'TaxObject'            => '02', // 02 = sí objeto de impuesto
                'Taxes'                => [[
                    'Name'        => 'IVA',
                    'Rate'        => $tasa,
                    'Base'        => $subtotal,
                    'Total'       => $ivaTotal,
                    'IsRetention' => false,
                    'IsFederalTax'=> true,
                ]],
                'Total'                => $total,
            ];
        }, $invoice['items']);

        $issuer = [
            'FiscalRegime' => $e['regimen_fiscal'],
            'Rfc'          => strtoupper(trim($e['rfc'])),
            // nombre_sat: nombre exacto del CSD (Facturama lo exige igual al SAT)
            'Name'         => mb_strtoupper($e['nombre_sat'] ?? $e['nombre']),
        ];

        $receiver = $r['es_publico'] ? [
            'Rfc'          => 'XAXX010101000',
            'Name'         => 'PUBLICO EN GENERAL',
            'CfdiUse'      => 'S01',
            'FiscalRegime' => '616',
            'TaxZipCode'   => $e['codigo_postal'] ?: '00000',
        ] : [
            'Rfc'          => strtoupper(trim($r['rfc'])),
            'Name'         => mb_strtoupper($r['nombre']),
            'CfdiUse'      => $r['uso_cfdi'],
            'FiscalRegime' => $r['regimen_fiscal'],
            'TaxZipCode'   => $r['codigo_postal'] ?: ($e['codigo_postal'] ?: '00000'),
        ];

        return [
            'Issuer'          => $issuer,
            'Receiver'        => $receiver,
            'CfdiType'        => $invoice['tipo'],
            'ExpeditionPlace' => $e['codigo_postal'] ?: '00000',
            'PaymentForm'     => $invoice['forma_pago'],
            'PaymentMethod'   => $invoice['metodo_pago'],
            'Serie'           => $invoice['serie'],
            'Folio'           => (string) $invoice['folio'],
            'Currency'        => 'MXN',
            'Items'           => $items,
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function decodeContent(?array $json): string
    {
        $content = $json['Content'] ?? null;
        if ($content === null) {
            throw new \Exception('Respuesta de Facturama sin contenido');
        }
        return base64_decode($content);
    }

    private function throwIfError($response, string $fallback): void
    {
        if ($response->successful()) return;

        $body = $response->json();
        \Illuminate\Support\Facades\Log::error('Facturama API error', [
            'status' => $response->status(),
            'body'   => $body,
            'raw'    => $response->body(),
        ]);

        $msg  = $body['Message'] ?? $body['message'] ?? $body['ModelState'] ?? null;

        if (is_array($msg)) {
            $msg = collect($msg)->flatten()->first();
        }

        throw new \Exception((string) ($msg ?? $fallback . ' [HTTP ' . $response->status() . ']: ' . $response->body()));
    }
}
