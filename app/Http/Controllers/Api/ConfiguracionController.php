<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ConfiguracionController extends Controller
{
    private function cacheKey(): string
    {
        $id = app()->bound('tenant_id') ? app('tenant_id') : 'global';
        return "ventas_configuracion_{$id}";
    }

    private function filePath(): string
    {
        $id = app()->bound('tenant_id') ? app('tenant_id') : 'global';
        return storage_path("app/configuracion_{$id}.json");
    }

    private function logoDir(): string
    {
        $id = app()->bound('tenant_id') ? app('tenant_id') : 'global';
        return "config/{$id}";
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
            'empresa'  => ['nullable', 'array'],
            'pos'      => ['nullable', 'array'],
            'impresion' => ['nullable', 'array'],
            'ticket'   => ['nullable', 'array'],
        ]);

        $configuracion = array_replace_recursive($this->configuracion(), $data);

        file_put_contents(
            $this->filePath(),
            json_encode($configuracion, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        Cache::forever($this->cacheKey(), $configuracion);

        return response()->json($configuracion);
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
        $logoUrl = $this->logoUrl();

        return response()->json(['url' => $logoUrl, 'logo_url' => $logoUrl]);
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
        $path = $this->filePath();
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data)) return $data;
        }

        return Cache::get($this->cacheKey(), $this->defaults());
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
                'descuento_max'    => 20,
                'impuesto'         => 16,
                'permitir_credito' => true,
                'permitir_tarjeta' => true,
                'permitir_efectivo' => true,
                'requiere_caja'    => true,
            ],
            'impresion' => [
                'tipo_impresora'   => 'smb',
                'impresora_ip'     => '192.168.100.77',
                'impresora_puerto' => '9100',
                'impresora_nombre' => 'STMicroelectronics_YZX_Printer',
                'ancho_papel'      => '80',
                'imprimir_auto'    => true,
                'mostrar_logo'     => true,
                'pie_ticket'       => 'Gracias por su compra',
                'copias'           => 1,
            ],
            'ticket' => [
                'encabezado'                => '',
                'mostrar_logo'              => true,
                'mostrar_datos_negocio'     => true,
                'mostrar_folio'             => true,
                'mostrar_fecha'             => true,
                'mostrar_cajero'            => true,
                'mostrar_sku'               => false,
                'mostrar_cantidad'          => true,
                'mostrar_precio_unitario'   => true,
                'mostrar_subtotal_linea'    => true,
                'mostrar_subtotal'          => true,
                'mostrar_descuento'         => true,
                'mostrar_iva'               => true,
                'mostrar_metodo_pago'       => true,
                'mostrar_cambio'            => true,
                'mostrar_qr'                => false,
                'pie_ticket'                => 'Gracias por su compra',
            ],
        ];
    }
}
