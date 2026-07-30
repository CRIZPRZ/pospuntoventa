<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Models\ConfiguracionSucursal;
use App\Models\Empresa;
use App\Models\WhatsAppConfig;
use App\Traits\EnviaCorreosTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

// Secciones que pertenecen a la empresa (legales, compartidas entre sucursales)
const EMPRESA_SECTIONS = ['empresa', 'facturacion', 'documentos'];

// Secciones que pertenecen a la sucursal (operativas, por tienda)
const SUCURSAL_SECTIONS = ['pos', 'impresion', 'ticket', 'notificaciones', 'nombre_comercial'];

class ConfiguracionController extends Controller
{
    use EnviaCorreosTrait;

    // ── IDs ──────────────────────────────────────────────────────────────────

    private function empresaId(): int
    {
        return app()->bound('tenant_id') ? (int) app('tenant_id') : 0;
    }

    private function sucursalId(): ?int
    {
        return app()->bound('sucursal_id') ? (int) app('sucursal_id') : null;
    }

    // ── Cache keys ───────────────────────────────────────────────────────────

    private function empresaCacheKey(): string
    {
        return 'ventas_configuracion_' . $this->empresaId();
    }

    private function sucursalCacheKey(): string
    {
        return 'ventas_config_sucursal_' . $this->sucursalId();
    }

    // ── Endpoints ────────────────────────────────────────────────────────────

    public function show(): \Illuminate\Http\JsonResponse
    {
        $config = $this->mergedConfig();
        $config = $this->attachWhatsAppConfig($config);
        $config['empresa']['logo_url'] = $this->logoUrl();

        return response()->json($config);
    }

    public function update(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'empresa'          => ['nullable', 'array'],
            'pos'              => ['nullable', 'array'],
            'impresion'        => ['nullable', 'array'],
            'ticket'           => ['nullable', 'array'],
            'notificaciones'   => ['nullable', 'array'],
            'facturacion'      => ['nullable', 'array'],
            'documentos'       => ['nullable', 'array'],
            'whatsapp'         => ['nullable', 'array'],
            'whatsapp_scope'   => ['nullable', 'string', 'in:empresa,sucursal'],
            'nombre_comercial' => ['nullable', 'string', 'max:150'],
        ]);

        $empresaData   = array_intersect_key($data, array_flip(EMPRESA_SECTIONS));
        $sucursalData  = array_intersect_key($data, array_flip(SUCURSAL_SECTIONS));

        if (!empty($empresaData)) {
            $merged = array_replace_recursive($this->empresaConfig(), $empresaData);
            Configuracion::updateOrCreate(
                ['empresa_id' => $this->empresaId()],
                ['config'     => $merged]
            );
            Cache::forever($this->empresaCacheKey(), $merged);
        }

        if (!empty($sucursalData) && $this->sucursalId()) {
            $merged = array_replace_recursive($this->sucursalConfig(), $sucursalData);
            ConfiguracionSucursal::updateOrCreate(
                ['sucursal_id' => $this->sucursalId()],
                ['empresa_id'  => $this->empresaId(), 'config' => $merged]
            );
            Cache::forever($this->sucursalCacheKey(), $merged);
        }

        if (array_key_exists('whatsapp', $data)) {
            $scope = $data['whatsapp_scope'] ?? 'empresa';
            $whatsapp = $this->storedWhatsAppPayload($data['whatsapp'] ?? []);
            if ($scope === 'sucursal' && $this->sucursalId()) {
                $merged = array_replace_recursive($this->sucursalConfig(), ['whatsapp' => $whatsapp]);
                ConfiguracionSucursal::updateOrCreate(
                    ['sucursal_id' => $this->sucursalId()],
                    ['empresa_id' => $this->empresaId(), 'config' => $merged]
                );
                Cache::forever($this->sucursalCacheKey(), $merged);
            } else {
                $merged = array_replace_recursive($this->empresaConfig(), ['whatsapp' => $whatsapp]);
                Configuracion::updateOrCreate(
                    ['empresa_id' => $this->empresaId()],
                    ['config' => $merged]
                );
                Cache::forever($this->empresaCacheKey(), $merged);
            }
        }

        $config = $this->mergedConfig();
        $config = $this->attachWhatsAppConfig($config);
        $config['empresa']['logo_url'] = $this->logoUrl();

        return response()->json($config);
    }

    public function uploadLogo(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['logo' => 'required|image|max:2048']);

        $file     = $request->file('logo');
        $filename = 'logo_' . time() . '.' . $file->getClientOriginalExtension();
        $dir      = $this->logoDir();

        $old = $this->getLogoFilename();
        if ($old) {
            Storage::disk('public')->delete("{$dir}/{$old}");
        }

        Storage::disk('public')->putFileAs($dir, $file, $filename);

        return response()->json(['url' => $this->logoUrl(), 'logo_url' => $this->logoUrl()]);
    }

    public function testCorreo(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $notif = $this->mergedConfig()['notificaciones'] ?? [];

        if (empty($notif['smtp_host'])) {
            return response()->json(['message' => 'Configura y guarda el servidor SMTP antes de enviar una prueba.'], 422);
        }

        $empresaNombre = $this->mergedConfig()['empresa']['nombre'] ?? 'Mi Empresa';

        $html = $this->buildEmailWrapper(
            '<p style="color:#374151;font-size:14px;margin:0">Este es un correo de prueba de la configuración SMTP de '
                . htmlspecialchars($empresaNombre) . '. Si lo recibiste, la configuración funciona correctamente.</p>',
            'Correo de prueba'
        );

        try {
            Mail::mailer($this->resolveMailer($notif))->html($html, function ($message) use ($data, $notif) {
                $message->to($data['email'])->subject('Correo de prueba');
                if (!empty($notif['smtp_from_email'])) {
                    $message->from($notif['smtp_from_email'], $notif['smtp_from_name'] ?? null);
                }
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'No se pudo enviar el correo: ' . $e->getMessage()], 422);
        }

        return response()->json(['message' => "Correo de prueba enviado a {$data['email']}"]);
    }

    public function deleteLogo(): \Illuminate\Http\JsonResponse
    {
        $filename = $this->getLogoFilename();
        if ($filename) {
            Storage::disk('public')->delete($this->logoDir() . '/' . $filename);
        }

        return response()->json(['message' => 'Logo eliminado']);
    }

    // ── Config readers ───────────────────────────────────────────────────────

    public function empresaConfig(): array
    {
        $cached = Cache::get($this->empresaCacheKey());
        if ($cached) return $cached;

        $row = Configuracion::where('empresa_id', $this->empresaId())->first();
        if ($row) {
            Cache::forever($this->empresaCacheKey(), $row->config);
            return $row->config;
        }

        return $this->defaultsEmpresa();
    }

    public function sucursalConfig(): array
    {
        $sucursalId = $this->sucursalId();
        if (!$sucursalId) return $this->defaultsSucursal();

        $cached = Cache::get($this->sucursalCacheKey());
        if ($cached) return $cached;

        $row = ConfiguracionSucursal::where('sucursal_id', $sucursalId)->first();
        if ($row) {
            Cache::forever($this->sucursalCacheKey(), $row->config);
            return $row->config;
        }

        return $this->defaultsSucursal();
    }

    /**
     * Merge empresa config (base) + sucursal config (override).
     * Shape idéntico al anterior — nada se rompe en el resto del sistema.
     */
    public function mergedConfig(): array
    {
        $empresa  = $this->empresaConfig();
        $sucursal = $this->sucursalConfig();

        return array_replace_recursive($empresa, $sucursal);
    }

    private function attachWhatsAppConfig(array $config): array
    {
        $empresaConfig = $this->empresaConfig();
        $sucursalConfig = $this->sucursalConfig();

        $empresaPublic = array_merge($this->defaultWhatsApp(), $this->storedWhatsAppPayload($empresaConfig['whatsapp'] ?? []));
        $sucursalPublic = $this->storedWhatsAppPayload($sucursalConfig['whatsapp'] ?? []);
        $hasSucursalOverride = is_array($sucursalPublic) && !empty($sucursalPublic);

        $resolved = $hasSucursalOverride
            ? array_replace_recursive($empresaPublic, $sucursalPublic)
            : $empresaPublic;

        $effectiveRow = $this->effectiveWhatsAppTechnicalConfig($hasSucursalOverride);
        $selectedProvider = $this->whatsAppProvider();
        $resolved['provider'] = $selectedProvider;
        $rowProvider = $this->normalizeWhatsAppProvider($effectiveRow?->provider);

        if ($effectiveRow && $rowProvider === $selectedProvider) {
            $resolved['status'] = $effectiveRow->status ?: ($resolved['status'] ?? 'disconnected');
            $resolved['business_name'] = $resolved['business_name'] ?: ($effectiveRow->business_name ?? '');
            $resolved['phone_number'] = $resolved['phone_number'] ?: ($effectiveRow->phone_number ?? '');
            $resolved['display_name'] = $effectiveRow->display_name ?? ($resolved['display_name'] ?? '');
            $resolved['connected_phone_number'] = $effectiveRow->connected_phone_number ?? ($resolved['connected_phone_number'] ?? '');
            $resolved['last_test_at'] = $effectiveRow->last_test_at?->format('Y-m-d H:i:s') ?? ($resolved['last_test_at'] ?? '');
            $resolved['last_error'] = $effectiveRow->last_error ?? ($resolved['last_error'] ?? '');
        } elseif ($effectiveRow && $rowProvider !== $selectedProvider) {
            $resolved['status'] = 'disconnected';
            $resolved['display_name'] = '';
            $resolved['connected_phone_number'] = '';
            $resolved['last_test_at'] = '';
            $resolved['last_error'] = '';
        }

        $resolved['scope_mode'] = $hasSucursalOverride ? 'sucursal' : 'empresa';
        $resolved['inherits_from_empresa'] = !$hasSucursalOverride;
        $resolved['empresa_default'] = $empresaPublic;

        $config['whatsapp'] = $resolved;

        return $config;
    }

    private function storedWhatsAppPayload(array $whatsapp): array
    {
        unset($whatsapp['empresa_default'], $whatsapp['inherits_from_empresa'], $whatsapp['scope_mode']);

        return $whatsapp;
    }

    private function effectiveWhatsAppTechnicalConfig(bool $hasSucursalOverride): ?WhatsAppConfig
    {
        if ($hasSucursalOverride && $this->sucursalId()) {
            $row = WhatsAppConfig::withoutGlobalScopes()
                ->where('empresa_id', $this->empresaId())
                ->where('sucursal_id', $this->sucursalId())
                ->first();

            if ($row) {
                return $row;
            }
        }

        return WhatsAppConfig::withoutGlobalScopes()
            ->where('empresa_id', $this->empresaId())
            ->whereNull('sucursal_id')
            ->first();
    }

    // ── Logo helpers ─────────────────────────────────────────────────────────

    private function logoDir(): string
    {
        return 'config/' . $this->empresaId();
    }

    private function logoUrl(): ?string
    {
        $filename = $this->getLogoFilename();
        if (!$filename) return null;
        return asset('storage/' . $this->logoDir() . '/' . $filename);
    }

    private function getLogoFilename(): ?string
    {
        $files = Storage::disk('public')->files($this->logoDir());
        foreach ($files as $file) {
            if (str_starts_with(basename($file), 'logo_')) {
                return basename($file);
            }
        }
        return null;
    }

    // ── Defaults ─────────────────────────────────────────────────────────────

    private function defaultsEmpresa(): array
    {
        return [
            'empresa' => [
                'nombre'    => 'Mi Empresa',
                'rfc'       => '',
                'direccion' => '',
                'telefono'  => '',
                'email'     => '',
                'sitio_web' => '',
            ],
            'facturacion' => [
                'activa'            => false,
                'ambiente'          => 'sandbox',
                'regimen_fiscal'    => '612',
                'codigo_postal'     => '',
                'serie'             => 'A',
                'folio_actual'      => 1,
                'emisor_registrado' => false,
            ],
            'whatsapp' => $this->defaultWhatsApp(),
        ];
    }

    private function defaultsSucursal(): array
    {
        return [
            'nombre_comercial' => '',
            'pos' => [
                'descuento_max'         => 20,
                'impuesto'              => 16,
                'permitir_credito'      => true,
                'permitir_tarjeta'      => true,
                'permitir_efectivo'     => true,
                'requiere_caja'         => true,
                'fondo_minimo_apertura' => 1000,
            ],
            'impresion' => [
                'tipo_impresora'   => 'browser',
                'conexion_tipo'    => 'red',
                'impresora_ip'     => '',
                'impresora_puerto' => '9100',
                'impresora_nombre' => '',
                'ancho_papel'      => '80',
                'velocidad'        => 'auto',
                'dispositivo_usb'  => '/dev/usb/lp0',
                'imprimir_auto'    => true,
                'mostrar_logo'     => false,
                'pie_ticket'       => 'Gracias por su compra',
                'copias'           => 1,
            ],
            'ticket' => [
                'encabezado'              => '',
                'mostrar_logo'            => false,
                'mostrar_datos_negocio'   => true,
                'mostrar_folio'           => true,
                'mostrar_fecha'           => true,
                'mostrar_cajero'          => true,
                'mostrar_sku'             => false,
                'mostrar_cantidad'        => true,
                'mostrar_precio_unitario' => true,
                'mostrar_subtotal_linea'  => true,
                'mostrar_subtotal'        => true,
                'mostrar_descuento'       => true,
                'mostrar_iva'             => true,
                'mostrar_metodo_pago'     => true,
                'mostrar_cambio'          => true,
                'mostrar_qr'              => false,
                'pie_ticket'              => 'Gracias por su compra',
            ],
            'notificaciones' => [
                'correos_cc'     => [],
                'smtp_host'      => '',
                'smtp_port'      => 587,
                'smtp_user'      => '',
                'smtp_password'  => '',
                'smtp_from_name' => '',
                'smtp_from_email'=> '',
                'color_primario' => '#2563eb',
                'mostrar_logo'   => true,
                'encabezado'     => '¡Gracias por su preferencia!',
                'intro'          => 'Adjuntamos el detalle de su pedido/cotización.',
                'pie'            => '',
                'notif_al_crear' => true,
            ],
        ];
    }

    private function defaultWhatsApp(): array
    {
        return [
            'provider' => $this->whatsAppProvider(),
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

    private function whatsAppProvider(): string
    {
        $provider = Empresa::withoutGlobalScopes()
            ->where('id', $this->empresaId())
            ->value('whatsapp_provider') ?: 'cloud_api';

        return $this->normalizeWhatsAppProvider($provider);
    }

    private function normalizeWhatsAppProvider(?string $provider): string
    {
        return $provider === 'baileys' ? 'baileys' : ($provider === 'disabled' ? 'disabled' : 'cloud_api');
    }
}
