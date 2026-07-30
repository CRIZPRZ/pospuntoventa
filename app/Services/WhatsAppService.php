<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\ConfiguracionSucursal;
use App\Models\WhatsAppConfig;
use Illuminate\Support\Facades\Cache;
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
        if ($this->usesBaileys($config)) {
            return app(BaileysWhatsAppService::class)->sendTextMessage($config, $to, $message);
        }

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
        if ($this->usesBaileys($config)) {
            try {
                return app(BaileysWhatsAppService::class)->sendUrlButtonMessage($config, $to, $body, $buttonText, $ticketUrl);
            } catch (\Throwable) {
                return $this->sendTextMessage($config, $to, $body . "\n\n" . $buttonText . ': ' . $ticketUrl);
            }
        }

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

    public function sendCfdiMessage(WhatsAppConfig $config, string $to, string $body, string $pdfUrl, string $xmlUrl): void
    {
        if ($this->usesBaileys($config)) {
            $this->sendTextMessage($config, $to, $body . "\nPDF: {$pdfUrl}\nXML: {$xmlUrl}");
            return;
        }

        try {
            $this->sendTicketMessage($config, $to, $body, $pdfUrl, '📄 Ver PDF');
        } catch (\Throwable) {
            $this->sendTextMessage($config, $to, $body . "\n📄 PDF: {$pdfUrl}");
        }
        try {
            $this->sendTicketMessage($config, $to, '📁 Archivo XML de tu factura:', $xmlUrl, '⬇️ Descargar XML');
        } catch (\Throwable) {
            $this->sendTextMessage($config, $to, "📁 XML: {$xmlUrl}");
        }
    }

    public function sendTemplateMessage(
        WhatsAppConfig $config,
        string $to,
        string $templateName = 'hello_world',
        string $languageCode = 'en_US'
    ): array {
        if ($this->usesBaileys($config)) {
            return $this->sendTextMessage($config, $to, 'Prueba de WhatsApp desde EventPOS.');
        }

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

    public function resolveBusinessName(int $empresaId, ?int $sucursalId, WhatsAppConfig $technicalConfig, array $publicConfig = []): string
    {
        $sucursalCfg = $sucursalId
            ? (ConfiguracionSucursal::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->first()?->config ?? [])
            : [];

        $empresaCfg = Configuracion::where('empresa_id', $empresaId)
            ->first()?->config ?? [];

        return ($sucursalCfg['nombre_comercial'] ?? null)
            ?: ($empresaCfg['empresa']['nombre'] ?? null)
            ?: $technicalConfig->display_name
            ?: $technicalConfig->business_name
            ?: ($publicConfig['business_name'] ?? 'Tu negocio');
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

    public function isConnected(?WhatsAppConfig $config): bool
    {
        if (!$config) {
            return false;
        }

        if ($this->usesBaileys($config)) {
            if (!$config->session_key) {
                return false;
            }

            try {
                $payload = app(BaileysWhatsAppService::class)->getStatus($config);
                $status = $payload['status'] ?? ($payload['connected'] ?? false ? 'connected' : ($config->status ?: 'disconnected'));
                $patch = [
                    'status' => $status,
                    'last_error' => $payload['last_error'] ?? null,
                ];

                if ($status === 'connected') {
                    $patch['connected_at'] = $config->connected_at ?: now();
                    $patch['disconnected_at'] = null;
                    $patch['connected_phone_number'] = $payload['phone_number'] ?? $config->connected_phone_number;
                    $patch['display_name'] = $payload['display_name'] ?? $config->display_name;
                } else {
                    $patch['disconnected_at'] = now();
                }

                $config->forceFill($patch)->save();
                $this->syncPublicBaileysStatus($config, $payload, $status);

                return $status === 'connected';
            } catch (\Throwable $e) {
                $config->forceFill([
                    'status' => 'error',
                    'last_error' => $e->getMessage(),
                    'disconnected_at' => now(),
                ])->save();
                $this->syncPublicBaileysStatus($config, ['last_error' => $e->getMessage()], 'error');

                return false;
            }
        }

        return (bool) ($config->access_token && $config->phone_number_id);
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

    private function usesBaileys(WhatsAppConfig $config): bool
    {
        return in_array($config->provider, ['baileys', 'whatsapp_web'], true);
    }

    private function syncPublicBaileysStatus(WhatsAppConfig $config, array $payload, string $status): void
    {
        $patch = [
            'status' => $status,
            'provider' => 'baileys',
            'display_name' => $payload['display_name'] ?? ($config->display_name ?? ''),
            'connected_phone_number' => $payload['phone_number'] ?? ($config->connected_phone_number ?? ''),
            'last_error' => $payload['last_error'] ?? '',
        ];

        if ($config->sucursal_id) {
            $row = ConfiguracionSucursal::firstOrCreate(
                ['empresa_id' => $config->empresa_id, 'sucursal_id' => $config->sucursal_id],
                ['config' => []]
            );
        } else {
            $row = Configuracion::firstOrCreate(
                ['empresa_id' => $config->empresa_id],
                ['config' => []]
            );
        }

        $current = $row->config ?? [];
        $current['whatsapp'] = array_replace_recursive($current['whatsapp'] ?? [], $patch);
        $row->config = $current;
        $row->save();

        Cache::forget('ventas_configuracion_' . $config->empresa_id);
        if ($config->sucursal_id) {
            Cache::forget('ventas_config_sucursal_' . $config->sucursal_id);
        }
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
