<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class FacturapiService
{
    private const BASE = 'https://www.facturapi.io/v2';

    // ─── Org management (user key) ───────────────────────────────────────────

    public function crearOrganizacion(string $nombre): array
    {
        $response = Http::withToken(config('services.facturapi.user_key'))
            ->post(self::BASE . '/organizations', ['name' => $nombre]);

        $this->throwIfError($response, 'Error al crear organización en Facturapi');

        $data = $response->json();

        return [
            'org_id'    => $data['id'],
            'live_key'  => $data['keys']['live'] ?? '',
            'test_key'  => $data['keys']['test'] ?? '',
        ];
    }

    public function subirCsd(string $orgKey, string $cerBase64, string $keyBase64, string $password): void
    {
        $response = Http::withToken($orgKey)
            ->attach('cer',      base64_decode($cerBase64), 'cert.cer')
            ->attach('key',      base64_decode($keyBase64), 'cert.key')
            ->attach('password', $password)
            ->put(self::BASE . '/organizations/me/certificate');

        $this->throwIfError($response, 'Error al subir CSD a Facturapi');
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
