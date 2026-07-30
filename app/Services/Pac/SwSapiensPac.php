<?php

namespace App\Services\Pac;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PAC SW Sapiens — API multiemisor.
 *
 * UNA cuenta nuestra (user/password en config). Cada tenant sube su CSD
 * bajo su RFC. Auth via Bearer token (TTL 2h, cacheado).
 *
 * Timbrado via /v3/cfdi33/issue/json/v4 con Content-Type: application/jsontoxml.
 * No requiere des-IVAr manualmente — los impuestos van explícitos en el payload.
 */
class SwSapiensPac implements PacContract
{
    public function key(): string
    {
        return 'sw_sapiens';
    }

    private function isSandbox(): bool
    {
        return (bool) config('services.sw_sapiens.sandbox', true);
    }

    private function servicesUrl(): string
    {
        return $this->isSandbox()
            ? 'https://services.test.sw.com.mx'
            : 'https://services.sw.com.mx';
    }

    private function apiUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api.test.sw.com.mx'
            : 'https://api.sw.com.mx';
    }

    private function getToken(): string
    {
        // Token estático (ej. token infinito de sandbox de Conectia) — evita regenerar token compartido
        $staticToken = config('services.sw_sapiens.token');
        if ($staticToken) {
            return $staticToken;
        }

        $cacheKey = 'sw_sapiens_token_' . ($this->isSandbox() ? 'sandbox' : 'prod');

        return Cache::remember($cacheKey, 6600, function () {
            $resp = Http::asJson()
                ->timeout(20)
                ->post($this->servicesUrl() . '/v2/security/authenticate', [
                    'user'     => config('services.sw_sapiens.user'),
                    'password' => config('services.sw_sapiens.password'),
                ]);

            if (!$resp->successful()) {
                throw new \Exception('SW Sapiens: error de autenticación [HTTP ' . $resp->status() . ']: ' . $resp->body());
            }

            $token = $resp->json('data.token') ?? $resp->json('token');
            if (!$token) {
                throw new \Exception('SW Sapiens: no se obtuvo token. Response: ' . $resp->body());
            }

            return $token;
        });
    }

    private function http()
    {
        return Http::withToken($this->getToken())->timeout(25);
    }

    // ─── PacContract ──────────────────────────────────────────────────────────

    public function setup(array $ctx): array
    {
        // No-op: el emisor queda registrado al subir su CSD.
        return ['org_id' => null];
    }

    public function subirCsd(array $ctx, string $cerBase64, string $keyBase64, string $password): void
    {
        try {
            $resp = $this->http()
                ->asJson()
                ->post($this->servicesUrl() . '/certificates/save', [
                    'type'     => 'stamp',
                    'b64Cer'   => $cerBase64,
                    'b64Key'   => $keyBase64,
                    'password' => $password,
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('No se pudo conectar con SW Sapiens al cargar el CSD.');
        }

        $this->throwIfError($resp, 'Error al cargar el CSD en SW Sapiens');
    }

    public function crearFactura(array $ctx, array $invoice): array
    {
        $payload = $this->buildPayload($invoice);

        try {
            $resp = Http::withToken($this->getToken())
                ->withHeaders(['Content-Type' => 'application/jsontoxml'])
                ->timeout(30)
                ->post($this->servicesUrl() . '/v3/cfdi33/issue/json/v4', $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('No se pudo conectar con SW Sapiens (timeout). Intenta de nuevo.');
        }

        $this->throwIfError($resp, 'Error al timbrar en SW Sapiens');

        $data = $resp->json();
        $uuid = $data['data']['uuid']
            ?? $data['data']['timbre']['uuid']
            ?? $data['uuid']
            ?? null;

        $xml = $data['data']['cfdi']
            ?? $data['data']['xml']
            ?? null;

        // pac_id = UUID (se usa para descarga y cancelación)
        return [
            'uuid'   => $uuid,
            'pac_id' => $uuid,
            'xml'    => $xml,
        ];
    }

    public function descargarXml(array $ctx, string $pacId): string
    {
        // pacId = UUID del CFDI
        try {
            $resp = $this->http()
                ->get($this->apiUrl() . '/datawarehouse/v1/live/' . $pacId);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('No se pudo conectar con SW Sapiens para descargar el XML.');
        }

        $this->throwIfError($resp, 'Error al descargar XML de SW Sapiens');

        $records = $resp->json('data.records') ?? [];
        if (empty($records)) {
            throw new \Exception('SW Sapiens: CFDI no encontrado con UUID ' . $pacId);
        }

        // El registro trae URL del XML o el contenido directo
        $xmlUrl = $records[0]['urlXml'] ?? $records[0]['xml_url'] ?? null;
        if ($xmlUrl) {
            $xmlResp = Http::timeout(20)->get($xmlUrl);
            if (!$xmlResp->successful()) {
                throw new \Exception('Error al descargar XML desde URL de SW Sapiens.');
            }
            return $xmlResp->body();
        }

        $xmlContent = $records[0]['cfdi'] ?? $records[0]['xml'] ?? null;
        if ($xmlContent) {
            return $xmlContent;
        }

        throw new \Exception('SW Sapiens: no se pudo obtener el contenido XML del CFDI.');
    }

    public function descargarPdf(array $ctx, string $pacId): string
    {
        // Usar XML ya almacenado si está disponible (evita latencia del datawarehouse)
        $xml = !empty($ctx['cfdi_xml']) ? $ctx['cfdi_xml'] : $this->descargarXml($ctx, $pacId);

        try {
            $resp = $this->http()
                ->asJson()
                ->post($this->apiUrl() . '/pdf/v1/api/GeneratePdf', [
                    'xmlContent' => $xml,
                    'templateId' => 'cfdi40',
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('No se pudo conectar con SW Sapiens para generar el PDF.');
        }

        $this->throwIfError($resp, 'Error al generar PDF en SW Sapiens');

        $contentB64 = $resp->json('data.contentB64') ?? $resp->json('contentB64');
        if (!$contentB64) {
            throw new \Exception('SW Sapiens: respuesta de PDF sin contenido base64.');
        }

        return base64_decode($contentB64);
    }

    public function cancelarFactura(array $ctx, string $pacId, string $motivo, ?string $uuidRelacionado): array
    {
        // RFC: preferir el del XML timbrado (evita mismatch si config RFC ≠ CSD RFC)
        $rfc = strtoupper(trim($ctx['rfc'] ?? ''));
        if (!empty($ctx['cfdi_xml']) && preg_match('/cfdi:Emisor[^>]+Rfc="([^"]+)"/i', $ctx['cfdi_xml'], $m)) {
            $rfc = strtoupper(trim($m[1]));
        }
        $url  = $this->servicesUrl() . "/cfdi33/cancel/{$rfc}/{$pacId}/{$motivo}";

        if ($motivo === '01' && $uuidRelacionado) {
            $url .= '/' . $uuidRelacionado;
        }

        Log::info('SW Sapiens cancel', ['url' => $url, 'rfc' => $rfc, 'uuid' => $pacId, 'motivo' => $motivo]);

        try {
            $resp = $this->http()->post($url);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \Exception('No se pudo conectar con SW Sapiens para cancelar el CFDI.');
        }

        Log::info('SW Sapiens cancel response', ['status' => $resp->status(), 'body' => $resp->body()]);

        $this->throwIfError($resp, 'Error al cancelar CFDI en SW Sapiens');

        return $resp->json() ?? [];
    }

    public function test(array $ctx): bool
    {
        try {
            $this->getToken();
            return true;
        } catch (\Exception $e) {
            throw new \Exception('SW Sapiens: no se pudo autenticar. ' . $e->getMessage());
        }
    }

    // ─── Payload JSON→XML ─────────────────────────────────────────────────────

    private function buildPayload(array $invoice): array
    {
        $tasa = (float) $invoice['tasa_iva'];
        $e    = $invoice['emisor'];
        $r    = $invoice['receptor'];

        $conceptos   = [];
        $totalBase   = 0;
        $totalIva    = 0;

        foreach ($invoice['items'] as $it) {
            $cant       = (float) $it['cantidad'];
            // Des-IVA: el precio capturado ya incluye IVA
            $unitBase   = round((float) $it['precio_con_iva'] / (1 + $tasa), 6);
            $importe    = round($unitBase * $cant, 2);
            $ivaImporte = round($importe * $tasa, 2);

            $totalBase += $importe;
            $totalIva  += $ivaImporte;

            $concepto = [
                'ClaveProdServ'        => $it['clave_sat'],
                'NoIdentificacion'     => !empty($it['codigo']) ? $it['codigo'] : 'NA',
                'Cantidad'             => (string) $cant,
                'ClaveUnidad'          => $it['clave_unidad'],
                'Unidad'               => $it['unidad'] ?? 'Pieza',
                'Descripcion'          => mb_strtoupper($it['descripcion']),
                'ValorUnitario'        => (string) $unitBase,
                'Importe'              => (string) $importe,
                'Descuento'            => '0',
                'ObjetoImp'            => '02',
                'Impuestos'            => [
                    'Traslados' => [[
                        'Base'         => (string) $importe,
                        'Impuesto'     => '002',
                        'TipoFactor'   => 'Tasa',
                        'TasaOCuota'   => number_format($tasa, 6, '.', ''),
                        'Importe'      => (string) $ivaImporte,
                    ]],
                ],
            ];

            $conceptos[] = $concepto;
        }

        $totalBase = round($totalBase, 2);
        $totalIva  = round($totalIva, 2);
        $total     = round($totalBase + $totalIva, 2);

        $receiver = $r['es_publico'] ? [
            'Rfc'                    => 'XAXX010101000',
            'Nombre'                 => 'PUBLICO EN GENERAL',
            'DomicilioFiscalReceptor'=> $e['codigo_postal'] ?: '00000',
            'RegimenFiscalReceptor'  => '616',
            'UsoCFDI'                => 'S01',
        ] : [
            'Rfc'                    => strtoupper(trim($r['rfc'])),
            'Nombre'                 => mb_strtoupper($r['nombre']),
            'DomicilioFiscalReceptor'=> $r['codigo_postal'] ?: ($e['codigo_postal'] ?: '00000'),
            'RegimenFiscalReceptor'  => $r['regimen_fiscal'],
            'UsoCFDI'                => $r['uso_cfdi'],
        ];

        return [
            'Version'           => '4.0',
            'Serie'             => $invoice['serie'],
            'Folio'             => (string) $invoice['folio'],
            'Fecha'             => now('America/Mexico_City')->format('Y-m-d\TH:i:s'),
            'Sello'             => '',
            'NoCertificado'     => '',
            'Certificado'       => '',
            'SubTotal'          => (string) $totalBase,
            'Descuento'         => '0',
            'Total'             => (string) $total,
            'Moneda'            => 'MXN',
            'Exportacion'       => '01',
            'TipoDeComprobante' => $invoice['tipo'],
            'MetodoPago'        => $invoice['metodo_pago'],
            'FormaPago'         => $invoice['forma_pago'],
            'LugarExpedicion'   => $e['codigo_postal'] ?: '00000',
            'Emisor'            => [
                'Rfc'           => strtoupper(trim($e['rfc'])),
                'Nombre'        => mb_strtoupper($e['nombre_sat'] ?? $e['nombre']),
                'RegimenFiscal' => $e['regimen_fiscal'],
            ],
            'Receptor'          => $receiver,
            'Conceptos'         => $conceptos,
            'Impuestos'         => [
                'TotalImpuestosTrasladados' => (string) $totalIva,
                'Traslados'                => [[
                    'Base'       => (string) $totalBase,
                    'Impuesto'   => '002',
                    'TipoFactor' => 'Tasa',
                    'TasaOCuota' => number_format($tasa, 6, '.', ''),
                    'Importe'    => (string) $totalIva,
                ]],
            ],
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function throwIfError($response, string $fallback): void
    {
        if ($response->successful()) return;

        $body = $response->json();
        Log::error('SW Sapiens API error', [
            'status' => $response->status(),
            'body'   => $body,
        ]);

        $msg = $body['message']
            ?? $body['data']['message']
            ?? $body['error']
            ?? null;

        throw new \Exception((string) ($msg ?? $fallback . ' [HTTP ' . $response->status() . ']: ' . $response->body()));
    }
}
