<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ConfiguracionController extends Controller
{
    private const CACHE_KEY = 'ventas_configuracion';

    public function show()
    {
        $config = $this->configuracion();
        $config['empresa']['logo_url'] = $this->logoUrl();

        return response()->json($config);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'empresa' => ['nullable', 'array'],
            'pos' => ['nullable', 'array'],
            'impresion' => ['nullable', 'array'],
            'ticket' => ['nullable', 'array'],
        ]);

        $configuracion = array_replace_recursive($this->configuracion(), $data);
        Cache::forever(self::CACHE_KEY, $configuracion);

        return response()->json($configuracion);
    }

    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|max:2048',
        ]);

        $file = $request->file('logo');
        $filename = 'logo_' . time() . '.' . $file->getClientOriginalExtension();

        $oldFilename = $this->getLogoFilename();
        if ($oldFilename) {
            Storage::disk('public')->delete('config/' . $oldFilename);
        }

        Storage::disk('public')->putFileAs('config', $file, $filename);

        $logoUrl = $this->logoUrl();

        return response()->json(['url' => $logoUrl, 'logo_url' => $logoUrl]);
    }

    public function deleteLogo()
    {
        $filename = $this->getLogoFilename();
        if ($filename) {
            Storage::disk('public')->delete('config/' . $filename);
        }

        return response()->json(['message' => 'Logo eliminado']);
    }

    private function configuracion(): array
    {
        return Cache::get(self::CACHE_KEY, [
            'empresa' => [
                'nombre' => 'Mi Empresa',
                'rfc' => '',
                'direccion' => '',
                'telefono' => '',
                'email' => '',
                'sitio_web' => '',
            ],
            'pos' => [
                'descuento_max' => 20,
                'impuesto' => 16,
                'permitir_credito' => true,
                'permitir_tarjeta' => true,
                'permitir_efectivo' => true,
                'requiere_caja' => true,
            ],
            'impresion' => [
                'imprimir_auto' => true,
                'mostrar_logo' => true,
                'pie_ticket' => 'Gracias por su compra',
                'copias' => 1,
            ],
            'ticket' => [
                'encabezado' => '',
                'mostrar_logo' => true,
                'mostrar_datos_negocio' => true,
                'mostrar_folio' => true,
                'mostrar_fecha' => true,
                'mostrar_cajero' => true,
                'mostrar_sku' => false,
                'mostrar_cantidad' => true,
                'mostrar_precio_unitario' => true,
                'mostrar_subtotal_linea' => true,
                'mostrar_subtotal' => true,
                'mostrar_descuento' => true,
                'mostrar_iva' => true,
                'mostrar_metodo_pago' => true,
                'mostrar_cambio' => true,
                'mostrar_qr' => false,
                'pie_ticket' => 'Gracias por su compra',
            ],
        ]);
    }

    private function logoUrl(): ?string
    {
        if (!$this->hasLogo()) {
            return null;
        }

        return asset('storage/config/' . $this->getLogoFilename());
    }

    private function hasLogo(): bool
    {
        return !empty($this->getLogoFilename());
    }

    private function getLogoFilename(): ?string
    {
        $files = Storage::disk('public')->files('config');

        foreach ($files as $file) {
            if (str_starts_with(basename($file), 'logo_')) {
                return basename($file);
            }
        }

        return null;
    }
}
