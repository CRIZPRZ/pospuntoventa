<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Models\ConfiguracionSucursal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

// Secciones que pertenecen a la empresa (legales, compartidas entre sucursales)
const EMPRESA_SECTIONS = ['empresa', 'facturacion'];

// Secciones que pertenecen a la sucursal (operativas, por tienda)
const SUCURSAL_SECTIONS = ['pos', 'impresion', 'ticket', 'notificaciones', 'nombre_comercial'];

class ConfiguracionController extends Controller
{
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

        $config = $this->mergedConfig();
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
}
