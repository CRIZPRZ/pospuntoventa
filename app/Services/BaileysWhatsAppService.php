<?php

namespace App\Services;

use App\Models\WhatsAppConfig;
use Illuminate\Support\Facades\Http;

class BaileysWhatsAppService
{
    public function startSession(WhatsAppConfig $config): array
    {
        return $this->request('post', '/sessions/start', [
            'tenant_id' => (string) $config->empresa_id,
            'session_key' => $config->session_key,
            'phone_number' => $config->phone_number,
            'business_name' => $config->business_name,
        ]);
    }

    public function getQr(WhatsAppConfig $config): array
    {
        return $this->request('get', '/sessions/' . urlencode($config->session_key) . '/qr');
    }

    public function getStatus(WhatsAppConfig $config): array
    {
        return $this->request('get', '/sessions/' . urlencode($config->session_key) . '/status');
    }

    public function logout(WhatsAppConfig $config): array
    {
        return $this->request('post', '/sessions/' . urlencode($config->session_key) . '/logout');
    }

    public function sendTextMessage(WhatsAppConfig $config, string $to, string $message): array
    {
        return $this->ensureMessageConfirmed($this->request('post', '/messages/send', [
            'tenant_id' => (string) $config->empresa_id,
            'session_key' => $config->session_key,
            'to' => $this->normalizePhone($to),
            'message' => $message,
            'type' => 'text',
        ]));
    }

    public function sendUrlButtonMessage(WhatsAppConfig $config, string $to, string $message, string $buttonText, string $url): array
    {
        return $this->ensureMessageConfirmed($this->request('post', '/messages/send', [
            'tenant_id' => (string) $config->empresa_id,
            'session_key' => $config->session_key,
            'to' => $this->normalizePhone($to),
            'message' => $message,
            'button_text' => $buttonText,
            'url' => $url,
            'type' => 'button_url',
        ]));
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $baseUrl = rtrim((string) config('services.whatsapp.baileys_url'), '/');
        if (!$baseUrl) {
            throw new \RuntimeException('Falta configurar WHATSAPP_BAILEYS_URL.');
        }

        $client = Http::acceptJson()->timeout(60);
        $token = (string) config('services.whatsapp.baileys_token', '');
        if ($token !== '') {
            $client = $client->withToken($token);
        }

        $response = $method === 'get'
            ? $client->get($baseUrl . $path, $payload)
            : $client->post($baseUrl . $path, $payload);

        if ($response->failed()) {
            $message = $response->json('message') ?: $response->json('error') ?: $response->body();
            throw new \RuntimeException('Baileys respondió con error: ' . $message);
        }

        return $response->json() ?? [];
    }

    private function ensureMessageConfirmed(array $payload): array
    {
        if (($payload['status'] ?? null) === 'pending') {
            $to = $payload['to'] ?? 'el número destino';
            throw new \RuntimeException("WhatsApp aceptó el mensaje para {$to}, pero no confirmó la entrega. Revisa que el teléfono tenga WhatsApp activo y que no haya bloqueo entre números.");
        }

        return $payload;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') return '';
        if (strlen($digits) === 10) {
            return '52' . $digits;
        }
        if (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            return '52' . substr($digits, 3);
        }
        return $digits;
    }
}
