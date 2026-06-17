<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\ConfiguracionSucursal;
use App\Models\WhatsAppConfig;
use Illuminate\Support\Facades\Http;

class WhatsAppService
{
    private const BASE_URL = 'https://graph.facebook.com';

    public function embeddedSignupConfig(): ?array
    {
        $appId = (string) config('services.whatsapp.app_id', '');
        $configId = (string) config('services.whatsapp.login_configuration_id', '');
        $redirectUri = (string) config('services.whatsapp.redirect_uri', '');

        if (!$appId || !$configId || !$redirectUri) {
            return null;
        }

        return [
            'app_id' => $appId,
            'config_id' => $configId,
            'redirect_uri' => $redirectUri,
            'api_version' => (string) config('services.whatsapp.api_version', 'v23.0'),
        ];
    }

    public function buildConnectUrl(?string $phoneNumber = null): ?string
    {
        $template = config('services.whatsapp.embedded_signup_url');
        if (!$template) {
            return null;
        }

        return strtr($template, [
            '{app_id}' => (string) config('services.whatsapp.app_id', ''),
            '{redirect_uri}' => urlencode((string) config('services.whatsapp.redirect_uri', '')),
            '{phone}' => urlencode((string) ($phoneNumber ?? '')),
        ]);
    }

    public function exchangeCodeForToken(string $code): string
    {
        $appId = (string) config('services.whatsapp.app_id', '');
        $appSecret = (string) config('services.whatsapp.app_secret', '');
        $redirectUri = (string) config('services.whatsapp.redirect_uri', '');
        $version = (string) config('services.whatsapp.api_version', 'v23.0');

        if (!$appId || !$appSecret || !$redirectUri) {
            throw new \RuntimeException('Falta configurar la app de Meta para intercambiar el código de WhatsApp.');
        }

        $response = Http::acceptJson()->get(self::BASE_URL . '/' . $version . '/oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message')
                ?? $response->json('message')
                ?? $response->body();

            throw new \RuntimeException('No se pudo intercambiar el código de Meta: ' . $error);
        }

        $token = $response->json('access_token');
        if (!$token) {
            throw new \RuntimeException('Meta no devolvió access_token al completar el onboarding.');
        }

        return $token;
    }

    public function sendTextMessage(WhatsAppConfig $config, string $to, string $message): array
    {
        if (!$config->access_token || !$config->phone_number_id) {
            throw new \RuntimeException('La conexión técnica de WhatsApp aún no está completa.');
        }

        $response = Http::withToken($config->access_token)
            ->acceptJson()
            ->post($this->messagesUrl($config->phone_number_id), [
                'messaging_product' => 'whatsapp',
                'to' => $this->normalizePhone($to),
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $message,
                ],
            ]);

        if ($response->failed()) {
            $error = $response->json('error.message')
                ?? $response->json('message')
                ?? $response->body();

            throw new \RuntimeException('Meta respondió con error: ' . $error);
        }

        return $response->json();
    }

    public function sendTicketMessage(WhatsAppConfig $config, string $to, string $body, string $ticketUrl, string $buttonText = 'Ver ticket completo'): array
    {
        if (!$config->access_token || !$config->phone_number_id) {
            throw new \RuntimeException('La conexión técnica de WhatsApp aún no está completa.');
        }

        $response = Http::withToken($config->access_token)
            ->acceptJson()
            ->post($this->messagesUrl($config->phone_number_id), [
                'messaging_product' => 'whatsapp',
                'to' => $this->normalizePhone($to),
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'cta_url',
                    'body' => ['text' => $body],
                    'action' => [
                        'name' => 'cta_url',
                        'parameters' => [
                            'display_text' => $buttonText,
                            'url' => $ticketUrl,
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            // cta_url requires special WABA permissions — fallback to plain text with URL
            return $this->sendTextMessage($config, $to, $body . "\n\n🔗 {$ticketUrl}");
        }

        return $response->json();
    }

    public function sendTemplateMessage(
        WhatsAppConfig $config,
        string $to,
        string $templateName = 'hello_world',
        string $languageCode = 'en_US'
    ): array {
        if (!$config->access_token || !$config->phone_number_id) {
            throw new \RuntimeException('La conexión técnica de WhatsApp aún no está completa.');
        }

        $response = Http::withToken($config->access_token)
            ->acceptJson()
            ->post($this->messagesUrl($config->phone_number_id), [
                'messaging_product' => 'whatsapp',
                'to' => $this->normalizePhone($to),
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => [
                        'code' => $languageCode,
                    ],
                ],
            ]);

        if ($response->failed()) {
            $error = $response->json('error.message')
                ?? $response->json('message')
                ?? $response->body();

            throw new \RuntimeException('Meta respondió con error: ' . $error);
        }

        return $response->json();
    }

    public function resolveTechnicalConfig(int $empresaId, ?int $sucursalId = null): ?WhatsAppConfig
    {
        if ($sucursalId) {
            $row = WhatsAppConfig::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->first();

            if ($row) {
                return $row;
            }
        }

        return WhatsAppConfig::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->whereNull('sucursal_id')
            ->first();
    }

    public function resolvePublicConfig(int $empresaId, ?int $sucursalId = null): array
    {
        $empresaConfig = Configuracion::where('empresa_id', $empresaId)->first()?->config ?? [];
        $empresaPublic = array_merge($this->defaultPublicConfig(), $empresaConfig['whatsapp'] ?? []);

        if (!$sucursalId) {
            return $empresaPublic;
        }

        $sucursalConfig = ConfiguracionSucursal::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->first()?->config ?? [];

        $sucursalPublic = $sucursalConfig['whatsapp'] ?? null;
        if (!is_array($sucursalPublic) || empty($sucursalPublic)) {
            return $empresaPublic;
        }

        return array_replace_recursive($empresaPublic, $sucursalPublic);
    }

    private function messagesUrl(string $phoneNumberId): string
    {
        $version = config('services.whatsapp.api_version', 'v23.0');
        return self::BASE_URL . '/' . $version . '/' . $phoneNumberId . '/messages';
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') return '';
        // 10-digit Mexican local number → prepend country code 52
        if (strlen($digits) === 10) {
            return '52' . $digits;
        }
        return $digits;
    }

    private function defaultPublicConfig(): array
    {
        return [
            'status' => 'disconnected',
            'phone_number' => '',
            'business_name' => '',
            'display_name' => '',
            'connected_phone_number' => '',
            'test_phone_number' => '',
            'last_test_at' => '',
            'last_error' => '',
            'auto_send_ticket' => true,
            'auto_send_quote' => false,
            'auto_send_order_ready' => false,
            'auto_send_invoice' => false,
            'auto_send_payment_reminder' => false,
        ];
    }
}
