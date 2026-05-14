<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacturapiService
{
    private const BASE = 'https://www.facturapi.io/v2';

    private function userKey(): string
    {
        return config('services.facturapi.user_key');
    }

    // ─── Org management (user key) ───────────────────────────────────────────

    public function crearOrganizacion(string $nombre): string
    {
        $response = Http::withToken($this->userKey())
            ->post(self::BASE . '/organizations', ['name' => $nombre]);

        $this->throwIfError($response, 'Error al crear organización en Facturapi');

        $data = $response->json();
        return $data['id'] ?? $data['_id'] ?? '';
    }

    /**
     * Obtiene la test API key de la organización.
     * GET /organizations/{org_id}/apikeys/test (auth: user key)
     * Response: string con el key completo.
     */
    public function getTestApiKey(string $orgId): string
    {
        $response = Http::withToken($this->userKey())
            ->get(self::BASE . "/organizations/{$orgId}/apikeys/test");

        $this->throwIfError($response, 'Error al obtener test API key de la organización');

        $data = $response->json();
        if (is_string($data)) return $data;
        if ($data === null) return trim($response->body());
        return $data['api_key'] ?? $data['secret_key'] ?? $data['key'] ?? '';
    }

    /**
     * Genera/renueva la live API key de la organización para obtener el secret completo.
     * PUT /organizations/{org_id}/apikeys/live (auth: user key)
     * listLiveApiKeys solo devuelve los primeros 12 chars; PUT renueva y devuelve el key completo.
     */
    public function getLiveApiKey(string $orgId): string
    {
        $response = Http::withToken($this->userKey())
            ->put(self::BASE . "/organizations/{$orgId}/apikeys/live");

        $this->throwIfError($response, 'Error al obtener live API key de la organización');

        $data = $response->json();
        if (is_string($data)) return $data;
        if ($data === null) return trim($response->body());
        return $data['api_key'] ?? $data['secret_key'] ?? $data['key'] ?? '';
    }

    public function subirCsd(string $orgId, string $cerBase64, string $keyBase64, string $password): void
    {
        $response = Http::withToken($this->userKey())
            ->attach('cer',      base64_decode($cerBase64), 'cert.cer')
            ->attach('key',      base64_decode($keyBase64), 'cert.key')
            ->attach('password', $password)
            ->put(self::BASE . "/organizations/{$orgId}/certificate");

        $this->throwIfError($response, 'Error al subir CSD a Facturapi');
    }

    public function actualizarLegalOrg(string $orgId, array $data): void
    {
        $response = Http::withToken($this->userKey())
            ->put(self::BASE . "/organizations/{$orgId}/legal", $data);

        $this->throwIfError($response, 'Error al actualizar datos legales de la organización');
    }

    // ─── Invoicing (org key) ─────────────────────────────────────────────────

    public function crearFactura(string $orgKey, array $payload): array
    {
        $response = Http::withToken($orgKey)
            ->post(self::BASE . '/invoices', $payload);

        $this->throwIfError($response, 'Error al crear factura en Facturapi');

        return $response->json();
    }

    public function descargarXml(string $orgKey, string $invoiceId): string
    {
        $response = Http::withToken($orgKey)
            ->get(self::BASE . "/invoices/{$invoiceId}/xml");

        $this->throwIfError($response, 'Error al descargar XML');

        return $response->body();
    }

    public function descargarPdf(string $orgKey, string $invoiceId): string
    {
        $response = Http::withToken($orgKey)
            ->get(self::BASE . "/invoices/{$invoiceId}/pdf");

        $this->throwIfError($response, 'Error al descargar PDF');

        return $response->body();
    }

    public function testOrg(string $orgKey): bool
    {
        $response = Http::withToken($orgKey)
            ->get(self::BASE . '/invoices?limit=1');

        $this->throwIfError($response, 'Clave de organización inválida');

        return true;
    }

    // ─── Helper ──────────────────────────────────────────────────────────────

    private function throwIfError($response, string $fallback): void
    {
        if ($response->successful()) return;

        $body = $response->json();
        $msg  = $body['message'] ?? $body['error'] ?? null;

        if (is_array($msg)) {
            $msg = collect($msg)->flatten()->first();
        }

        throw new \Exception((string) ($msg ?? $fallback . ' [HTTP ' . $response->status() . ']: ' . $response->body()));
    }
}
