<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class FacturamaService
{
    private string $baseUrl;
    private string $basicAuth;

    public function __construct(string $ambiente = 'sandbox')
    {
        $this->baseUrl = $ambiente === 'production'
            ? 'https://www.facturama.mx'
            : 'https://apisandbox.facturama.mx';

        $this->basicAuth = base64_encode(
            config('services.facturama.user') . ':' . config('services.facturama.password')
        );
    }

    private function auth(): array
    {
        return ['Authorization' => 'Basic ' . $this->basicAuth];
    }

    // ─── Emisor ──────────────────────────────────────────────────────────────

    /**
     * Registra el RFC del tenant en Facturama y sube su CSD en un solo paso.
     */
    public function registrarEmisor(string $rfc, string $cerBase64, string $keyBase64, string $password): void
    {
        $response = Http::withHeaders($this->auth())
            ->attach('certificate', base64_decode($cerBase64), 'cert.cer')
            ->attach('privateKey',  base64_decode($keyBase64), 'cert.key')
            ->attach('password', $password)
            ->post($this->baseUrl . '/api-lite/taxpayers');

        $this->throwIfError($response, 'Error al registrar CSD en Facturama');
    }

    public function testConexion(): bool
    {
        $response = Http::withHeaders($this->auth())
            ->get($this->baseUrl . '/api-lite/taxpayers');

        $this->throwIfError($response, 'Credenciales de Facturama inválidas');

        return true;
    }

    // ─── CFDI ─────────────────────────────────────────────────────────────────

    public function crearFactura(array $payload): array
    {
        $response = Http::withHeaders($this->auth())
            ->post($this->baseUrl . '/api-lite/3/cfdis', $payload);

        $this->throwIfError($response, 'Error al timbrar CFDI en Facturama');

        return $response->json();
    }

    public function descargarXml(string $id): string
    {
        $response = Http::withHeaders($this->auth())
            ->get($this->baseUrl . "/api-lite/3/cfdis/{$id}/xml");

        $this->throwIfError($response, 'Error al descargar XML');

        return $response->body();
    }

    public function descargarPdf(string $id): string
    {
        $response = Http::withHeaders($this->auth())
            ->get($this->baseUrl . "/api-lite/3/cfdis/{$id}/pdf");

        $this->throwIfError($response, 'Error al descargar PDF');

        return $response->body();
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function throwIfError($response, string $fallback): void
    {
        if ($response->successful()) return;

        $body = $response->json();
        $msg  = $body['message'] ?? $body['Message'] ?? $body['Details'] ?? $body['detail'] ?? null;

        if ($msg === null && isset($body['ModelState'])) {
            $msg = collect($body['ModelState'])->flatten()->first();
        }

        if ($msg === null && isset($body[0])) {
            $msg = $body[0];
        }

        if (is_array($msg)) {
            $msg = collect($msg)->flatten()->first();
        }

        $msg = $msg ?? ($fallback . ' [HTTP ' . $response->status() . ']: ' . $response->body());

        throw new \Exception((string) $msg);
    }
}
