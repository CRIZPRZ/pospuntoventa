<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ConfiguracionController extends Controller
{
    private function empresaId(): int
    {
        return app()->bound('tenant_id') ? (int) app('tenant_id') : 0;
    }

    private function cacheKey(): string
    {
        return 'ventas_configuracion_' . $this->empresaId();
    }

    private function logoDir(): string
    {
        return 'config/' . $this->empresaId();
    }

    public function show()
    {
        $config = $this->configuracion();
        $config['empresa']['logo_url'] = $this->logoUrl();

        return response()->json($config);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'empresa'        => ['nullable', 'array'],
            'pos'            => ['nullable', 'array'],
            'impresion'      => ['nullable', 'array'],
            'ticket'         => ['nullable', 'array'],
            'notificaciones' => ['nullable', 'array'],
            'facturacion'    => ['nullable', 'array'],
        ]);

        $merged = array_replace_recursive($this->configuracion(), $data);

        Configuracion::updateOrCreate(
            ['empresa_id' => $this->empresaId()],
            ['config'     => $merged]
        );

        Cache::forever($this->cacheKey(), $merged);

        return response()->json($merged);
    }

    public function uploadLogo(Request $request)
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

    public function deleteLogo()
    {
        $filename = $this->getLogoFilename();
        if ($filename) {
            Storage::disk('public')->delete($this->logoDir() . '/' . $filename);
        }

        return response()->json(['message' => 'Logo eliminado']);
    }

    private function configuracion(): array
    {
        $cached = Cache::get($this->cacheKey());
        if ($cached) return $cached;

        $row = Configuracion::where('empresa_id', $this->empresaId())->first();
        if ($row) {
            Cache::forever($this->cacheKey(), $row->config);
            return $row->config;
        }

        return $this->defaults();
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

    private function defaults(): array
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
            'pos' => [
                'descuento_max'           => 20,
                'impuesto'                => 16,
                'permitir_credito'        => true,
                'permitir_tarjeta'        => true,
                'permitir_efectivo'       => true,
                'requiere_caja'           => true,
                'fondo_minimo_apertura'   => 1000,
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
                'color_primario' => '#2563eb',
                'mostrar_logo'   => true,
                'encabezado'     => '¡Gracias por su preferencia!',
                'intro'          => 'Adjuntamos el detalle de su pedido/cotización.',
                'pie'            => '',
                'notif_al_crear' => true,
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
}
