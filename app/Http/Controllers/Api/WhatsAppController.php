<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Models\ConfiguracionSucursal;
use App\Models\Empresa;
use App\Models\WhatsAppConfig;
use App\Services\BaileysWhatsAppService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WhatsAppController extends Controller
{
    public function __construct(
        private readonly WhatsAppService $service,
        private readonly BaileysWhatsAppService $baileys
    ) {
    }

    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', 'string', 'in:empresa,sucursal'],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'business_name' => ['nullable', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:150'],
            'connected_phone_number' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'phone_number_id' => ['nullable', 'string', 'max:100'],
            'whatsapp_business_account_id' => ['nullable', 'string', 'max:100'],
            'access_token' => ['nullable', 'string'],
        ]);

        $scope = $this->scope($data);
        $provider = $this->provider();

        if ($provider === 'disabled') {
            return response()->json(['message' => 'WhatsApp está desactivado para esta empresa.'], 422);
        }

        if ($provider === 'baileys') {
            return $this->connectBaileys($data, $scope);
        }

        $hasTechnicalConnection = !empty($data['access_token']) && !empty($data['phone_number_id']);

        // Siempre incluir status en el config público cuando hay conexión técnica
        $publicPatch = $this->publicPayload($data);
        if ($hasTechnicalConnection) {
            $publicPatch['status'] = 'connected';
            $publicPatch['last_error'] = '';
        }
        $this->savePublicConfig($publicPatch, $scope);

        $technicalPayload = [
            'empresa_id' => $this->empresaId(),
            'sucursal_id' => $scope === 'sucursal' ? $this->sucursalId() : null,
            'provider' => 'cloud_api',
            'business_name' => $data['business_name'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
        ];

        if ($hasTechnicalConnection) {
            $technicalPayload = array_merge($technicalPayload, [
                'display_name' => $data['display_name'] ?? ($data['business_name'] ?? null),
                'connected_phone_number' => $data['connected_phone_number'] ?? $data['phone_number'] ?? null,
                'phone_number_id' => $data['phone_number_id'],
                'whatsapp_business_account_id' => $data['whatsapp_business_account_id'] ?? null,
                'access_token' => $data['access_token'],
                'status' => 'connected',
                'last_error' => null,
            ]);
        } else {
            $existing = $this->technicalConfig($scope, false);
            $technicalPayload = array_merge($technicalPayload, [
                'status' => $existing?->access_token && $existing?->phone_number_id ? 'connected' : 'pending',
            ]);
        }

        WhatsAppConfig::updateOrCreate(
            ['empresa_id' => $this->empresaId(), 'sucursal_id' => $scope === 'sucursal' ? $this->sucursalId() : null],
            $technicalPayload
        );

        $connectUrl = $this->service->buildConnectUrl($data['phone_number']);
        $embeddedSignup = $this->service->embeddedSignupConfig();

        return response()->json([
            'message' => $embeddedSignup || $connectUrl
                ? 'Configuración guardada. Continúa la conexión con Meta.'
                : 'Configuración guardada. Falta completar la conexión técnica con Meta.',
            'status' => $hasTechnicalConnection ? 'connected' : 'pending',
            'scope' => $scope,
            'connect_url' => $connectUrl,
            'embedded_signup' => $embeddedSignup,
        ]);
    }

    public function qr(Request $request): JsonResponse
    {
        $scope = $this->scope($request->validate([
            'scope' => ['nullable', 'string', 'in:empresa,sucursal'],
        ]));

        if ($this->provider() !== 'baileys') {
            return response()->json(['message' => 'La empresa no tiene Baileys como proveedor activo.'], 422);
        }

        $config = $this->technicalConfig($scope, false);
        if (!$config || $config->provider !== 'baileys') {
            return $this->connectBaileys($this->publicConfigForScope($scope), $scope);
        }

        try {
            $payload = $this->baileys->getQr($config);
        } catch (\Throwable $e) {
            $config->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            $this->savePublicConfig(['status' => 'error', 'last_error' => $e->getMessage(), 'provider' => 'baileys'], $scope);
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hasQrImage = !empty($payload['image'] ?? null) || !empty($payload['qr_image'] ?? null);
        $status = $payload['status'] ?? ($hasQrImage ? 'qr_pending' : ($config->status ?: 'disconnected'));
        $config->update([
            'status' => $status,
            'last_error' => $payload['last_error'] ?? null,
            'connected_phone_number' => $status === 'connected' ? ($payload['phone_number'] ?? $config->connected_phone_number) : $config->connected_phone_number,
            'display_name' => $status === 'connected' ? ($payload['display_name'] ?? $config->display_name) : $config->display_name,
            'connected_at' => $status === 'connected' ? ($config->connected_at ?: now()) : $config->connected_at,
            'disconnected_at' => $status === 'connected' ? null : $config->disconnected_at,
        ]);
        $this->savePublicConfig([
            'status' => $status,
            'provider' => 'baileys',
            'display_name' => $status === 'connected' ? ($payload['display_name'] ?? ($config->display_name ?? '')) : ($config->display_name ?? ''),
            'connected_phone_number' => $status === 'connected' ? ($payload['phone_number'] ?? ($config->connected_phone_number ?? '')) : ($config->connected_phone_number ?? ''),
            'last_error' => $payload['last_error'] ?? '',
        ], $scope);

        return response()->json([
            'message' => 'QR obtenido correctamente.',
            'qr' => $payload,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $scope = $this->scope($request->validate([
            'scope' => ['nullable', 'string', 'in:empresa,sucursal'],
        ]));

        $config = $this->technicalConfig($scope, true);
        if (!$config) {
            return response()->json(['status' => 'disconnected']);
        }

        if ($config->provider !== 'baileys') {
            return response()->json(['status' => $this->service->isConnected($config) ? 'connected' : ($config->status ?: 'disconnected')]);
        }

        try {
            $payload = $this->baileys->getStatus($config);
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
            }
            if ($status === 'disconnected') {
                $patch['disconnected_at'] = now();
            }
            $config->update($patch);
            $this->savePublicConfig([
                'status' => $status,
                'provider' => 'baileys',
                'display_name' => $payload['display_name'] ?? ($config->display_name ?? ''),
                'connected_phone_number' => $payload['phone_number'] ?? ($config->connected_phone_number ?? ''),
                'last_error' => $payload['last_error'] ?? '',
            ], $scope);

            return response()->json(['status' => $status, 'data' => $payload]);
        } catch (\Throwable $e) {
            $config->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            $this->savePublicConfig(['status' => 'error', 'last_error' => $e->getMessage(), 'provider' => 'baileys'], $scope);
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', 'string', 'in:empresa,sucursal'],
            'test_phone_number' => ['required', 'string', 'max:40'],
        ]);

        $scope = $this->scope($data);
        $config = $this->technicalConfig($scope, true);
        if (!$config) {
            return response()->json([
                'message' => 'Primero guarda y conecta el número de WhatsApp del negocio.',
            ], 422);
        }

        if (!$this->service->isConnected($config)) {
            $config->update([
                'status' => 'pending',
                'last_error' => $config->provider === 'baileys'
                    ? 'La sesión de WhatsApp Web aún no está conectada.'
                    : 'La conexión técnica con Meta aún no está completa.',
            ]);

            return response()->json([
                'message' => $config->provider === 'baileys'
                    ? 'La sesión de WhatsApp Web todavía no está lista. Escanea el QR y vuelve a intentar.'
                    : 'La conexión técnica con Meta todavía no está lista. Completa el onboarding o registra las credenciales del número.',
            ], 422);
        }

        $businessName = $config->display_name ?: $config->business_name ?: 'Tu negocio';
        $message = "Prueba de WhatsApp desde {$businessName}. Si recibiste este mensaje, la conexión de EventPOS está lista. " . now()->format('Y-m-d H:i:s');

        try {
            $response = $this->service->sendTextMessage($config, $data['test_phone_number'], $message);
            $notice = 'Mensaje de prueba enviado correctamente.';
        } catch (\Throwable $e) {
            $shouldFallbackToTemplate = str_contains(strtolower($e->getMessage()), '24 hours have passed');

            if (!$shouldFallbackToTemplate) {
                $config->update([
                    'status' => 'error',
                    'last_error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }

            try {
                $response = $this->service->sendTemplateMessage($config, $data['test_phone_number']);
                $notice = 'Prueba enviada con la plantilla hello_world de Meta.';
            } catch (\Throwable $templateError) {
                $config->update([
                    'status' => 'error',
                    'last_error' => $templateError->getMessage(),
                ]);

                return response()->json([
                    'message' => $templateError->getMessage(),
                ], 422);
            }
        }

        $config->update([
            'status' => 'connected',
            'last_test_at' => now(),
            'last_error' => null,
        ]);

        return response()->json([
            'message' => $notice,
            'meta' => $response,
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', 'string', 'in:empresa,sucursal'],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'business_name' => ['nullable', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:150'],
            'connected_phone_number' => ['nullable', 'string', 'max:40'],
            'phone_number_id' => ['required', 'string', 'max:100'],
            'whatsapp_business_account_id' => ['nullable', 'string', 'max:100'],
            'waba_id' => ['nullable', 'string', 'max:100'],
            'access_token' => ['nullable', 'string'],
            'code' => ['nullable', 'string'],
        ]);

        $scope = $this->scope($data);
        $accessToken = $data['access_token'] ?? null;

        if (!$accessToken && !empty($data['code'])) {
            $accessToken = $this->service->exchangeCodeForToken($data['code']);
        }

        if (!$accessToken) {
            return response()->json([
                'message' => 'Meta no devolvió el token de acceso para completar la conexión.',
            ], 422);
        }

        $wabaId = $data['whatsapp_business_account_id'] ?? $data['waba_id'] ?? null;

        $config = WhatsAppConfig::updateOrCreate(
            ['empresa_id' => $this->empresaId(), 'sucursal_id' => $scope === 'sucursal' ? $this->sucursalId() : null],
            [
                'empresa_id' => $this->empresaId(),
                'sucursal_id' => $scope === 'sucursal' ? $this->sucursalId() : null,
                'provider' => 'cloud_api',
                'business_name' => $data['business_name'] ?? null,
                'phone_number' => $data['phone_number'] ?? ($data['connected_phone_number'] ?? null),
                'display_name' => $data['display_name'] ?? ($data['business_name'] ?? null),
                'connected_phone_number' => $data['connected_phone_number'] ?? ($data['phone_number'] ?? null),
                'phone_number_id' => $data['phone_number_id'],
                'whatsapp_business_account_id' => $wabaId,
                'access_token' => $accessToken,
                'status' => 'connected',
                'last_error' => null,
            ]
        );

        $this->savePublicConfig([
            'status' => 'connected',
            'phone_number' => $config->phone_number ?? '',
            'business_name' => $config->business_name ?? '',
            'display_name' => $config->display_name ?? '',
            'connected_phone_number' => $config->connected_phone_number ?? '',
            'last_error' => '',
        ], $scope);

        return response()->json([
            'message' => 'WhatsApp conectado correctamente.',
            'scope' => $scope,
            'status' => 'connected',
        ]);
    }

    public function disconnect(): JsonResponse
    {
        $scope = $this->scope(request()->validate([
            'scope' => ['nullable', 'string', 'in:empresa,sucursal'],
        ]));
        $config = $this->technicalConfig($scope, false);

        if ($config) {
            if ($config->provider === 'baileys') {
                try {
                    $this->baileys->logout($config);
                } catch (\Throwable) {
                    // El logout local no debe impedir limpiar la conexión en EventPOS.
                }
            }

            if ($scope === 'sucursal') {
                $config->delete();
            } else {
                $config->update([
                    'display_name' => null,
                    'connected_phone_number' => null,
                    'phone_number_id' => null,
                    'whatsapp_business_account_id' => null,
                    'access_token' => null,
                    'status' => 'disconnected',
                    'disconnected_at' => now(),
                    'last_test_at' => null,
                    'last_error' => null,
                ]);
            }
        }

        if ($scope === 'sucursal') {
            $this->clearSucursalPublicConfig();
        } else {
            $this->savePublicConfig([
                'status' => 'disconnected',
                'display_name' => '',
                'connected_phone_number' => '',
                'test_phone_number' => '',
                'last_test_at' => '',
                'last_error' => '',
            ], 'empresa');
        }

        return response()->json([
            'message' => $scope === 'sucursal'
                ? 'WhatsApp de la sucursal eliminado. Ahora se usará el número general de la empresa.'
                : 'WhatsApp desconectado correctamente.',
        ]);
    }

    private function empresaId(): int
    {
        return app()->bound('tenant_id') ? (int) app('tenant_id') : 0;
    }

    private function cacheKey(): string
    {
        return 'ventas_configuracion_' . $this->empresaId();
    }

    private function baseConfig(): array
    {
        $cached = Cache::get($this->cacheKey());
        if ($cached) {
            return $cached;
        }

        $row = Configuracion::where('empresa_id', $this->empresaId())->first();
        return $row?->config ?? [];
    }

    private function technicalConfig(string $scope, bool $withFallback): ?WhatsAppConfig
    {
        $query = WhatsAppConfig::withoutGlobalScopes()
            ->where('empresa_id', $this->empresaId())
            ->when($scope === 'sucursal', fn ($q) => $q->where('sucursal_id', $this->sucursalId()))
            ->when($scope === 'empresa', fn ($q) => $q->whereNull('sucursal_id'));

        $row = $query->first();
        if ($row || !$withFallback || $scope !== 'sucursal') {
            return $row;
        }

        return WhatsAppConfig::withoutGlobalScopes()
            ->where('empresa_id', $this->empresaId())
            ->whereNull('sucursal_id')
            ->first();
    }

    private function savePublicConfig(array $patch, string $scope): void
    {
        $patch = $this->storedPublicConfig($patch);

        if ($scope === 'sucursal' && $this->sucursalId()) {
            $config = $this->branchBaseConfig();
            $config['whatsapp'] = array_merge($this->storedPublicConfig($config['whatsapp'] ?? []), $patch);

            ConfiguracionSucursal::updateOrCreate(
                ['sucursal_id' => $this->sucursalId()],
                ['empresa_id' => $this->empresaId(), 'config' => $config]
            );

            Cache::forever($this->branchCacheKey(), $config);
            return;
        }

        $config = $this->baseConfig();
        $config['whatsapp'] = array_merge($this->storedPublicConfig($config['whatsapp'] ?? $this->defaultPublicConfig()), $patch);

        Configuracion::updateOrCreate(
            ['empresa_id' => $this->empresaId()],
            ['config' => $config]
        );

        Cache::forever($this->cacheKey(), $config);
    }

    private function defaultPublicConfig(): array
    {
        return [
            'provider' => $this->provider(),
            'status' => 'disconnected',
            'phone_number' => '',
            'business_name' => '',
            'display_name' => '',
            'connected_phone_number' => '',
            'test_phone_number' => '',
            'last_test_at' => '',
            'last_error' => '',
            'auto_send_ticket' => false,
            'auto_send_quote' => false,
            'auto_send_order_ready' => false,
            'auto_send_invoice' => false,
            'auto_send_payment_reminder' => false,
        ];
    }

    private function publicPayload(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'status',
            'provider',
            'phone_number',
            'business_name',
            'display_name',
            'connected_phone_number',
            'test_phone_number',
            'last_test_at',
            'last_error',
            'auto_send_ticket',
            'auto_send_quote',
            'auto_send_order_ready',
            'auto_send_invoice',
            'auto_send_payment_reminder',
        ]));
    }

    private function scope(array $data): string
    {
        $scope = $data['scope'] ?? 'empresa';
        if ($scope === 'sucursal' && !$this->sucursalId()) {
            return 'empresa';
        }

        return $scope;
    }

    private function sucursalId(): ?int
    {
        return app()->bound('sucursal_id') ? (int) app('sucursal_id') : null;
    }

    private function provider(): string
    {
        $provider = Empresa::withoutGlobalScopes()
            ->where('id', $this->empresaId())
            ->value('whatsapp_provider') ?: 'cloud_api';

        return $provider === 'baileys' ? 'baileys' : ($provider === 'disabled' ? 'disabled' : 'cloud_api');
    }

    private function connectBaileys(array $data, string $scope): JsonResponse
    {
        $sessionKey = 'empresa-' . $this->empresaId() . ($scope === 'sucursal' && $this->sucursalId() ? '-sucursal-' . $this->sucursalId() : '');

        $config = WhatsAppConfig::updateOrCreate(
            ['empresa_id' => $this->empresaId(), 'sucursal_id' => $scope === 'sucursal' ? $this->sucursalId() : null],
            [
                'empresa_id' => $this->empresaId(),
                'sucursal_id' => $scope === 'sucursal' ? $this->sucursalId() : null,
                'provider' => 'baileys',
                'session_key' => $sessionKey,
                'business_name' => $data['business_name'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
                'display_name' => $data['display_name'] ?? ($data['business_name'] ?? null),
                'connected_phone_number' => $data['connected_phone_number'] ?? null,
                'status' => 'qr_pending',
                'last_error' => null,
            ]
        );

        $this->savePublicConfig(array_merge($this->publicPayload($data), [
            'provider' => 'baileys',
            'status' => 'qr_pending',
            'last_error' => '',
        ]), $scope);

        try {
            $payload = $this->baileys->startSession($config);
        } catch (\Throwable $e) {
            $config->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            $this->savePublicConfig(['provider' => 'baileys', 'status' => 'error', 'last_error' => $e->getMessage()], $scope);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $qr = $payload['qr'] ?? $payload;
        $hasQr = !empty($qr['image'] ?? null)
            || !empty($qr['qr_image'] ?? null)
            || !empty($qr['code'] ?? null)
            || !empty($qr['qr'] ?? null);
        $status = $qr['status'] ?? $payload['status'] ?? ($hasQr ? 'qr_pending' : 'disconnected');
        $lastError = $qr['last_error'] ?? null;

        $config->update([
            'status' => $status,
            'last_error' => $lastError,
        ]);
        $this->savePublicConfig([
            'provider' => 'baileys',
            'status' => $status,
            'last_error' => $lastError ?: '',
        ], $scope);

        if (!$hasQr && $status !== 'connected') {
            return response()->json([
                'message' => $lastError ?: 'WhatsApp no pudo generar el QR. Intenta nuevamente en unos segundos.',
                'status' => $status,
            ], 422);
        }

        return response()->json([
            'message' => $status === 'connected'
                ? 'WhatsApp ya está conectado.'
                : 'QR generado. Escanea WhatsApp Web para terminar la conexión.',
            'status' => $status,
            'scope' => $scope,
            'qr' => $qr,
        ]);
    }

    private function publicConfigForScope(string $scope): array
    {
        $empresaWhatsapp = $this->storedPublicConfig($this->baseConfig()['whatsapp'] ?? []);
        if ($scope !== 'sucursal' || !$this->sucursalId()) {
            return $empresaWhatsapp;
        }

        return array_replace_recursive($empresaWhatsapp, $this->storedPublicConfig($this->branchBaseConfig()['whatsapp'] ?? []));
    }

    private function storedPublicConfig(array $whatsapp): array
    {
        unset($whatsapp['empresa_default'], $whatsapp['inherits_from_empresa'], $whatsapp['scope_mode']);

        return $whatsapp;
    }

    private function branchCacheKey(): string
    {
        return 'ventas_config_sucursal_' . $this->sucursalId();
    }

    private function branchBaseConfig(): array
    {
        if (!$this->sucursalId()) {
            return [];
        }

        $cached = Cache::get($this->branchCacheKey());
        if ($cached) {
            return $cached;
        }

        $row = ConfiguracionSucursal::where('sucursal_id', $this->sucursalId())->first();
        return $row?->config ?? [];
    }

    private function clearSucursalPublicConfig(): void
    {
        if (!$this->sucursalId()) {
            return;
        }

        $config = $this->branchBaseConfig();
        unset($config['whatsapp']);

        if (empty($config)) {
            ConfiguracionSucursal::where('sucursal_id', $this->sucursalId())->delete();
            Cache::forget($this->branchCacheKey());
            return;
        }

        ConfiguracionSucursal::updateOrCreate(
            ['sucursal_id' => $this->sucursalId()],
            ['empresa_id' => $this->empresaId(), 'config' => $config]
        );

        Cache::forever($this->branchCacheKey(), $config);
    }
}
