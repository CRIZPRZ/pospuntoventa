<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Models\Venta;
use App\Services\FacturapiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FacturacionController extends Controller
{
    private function empresaId(): int
    {
        return app()->bound('tenant_id') ? (int) app('tenant_id') : 0;
    }

    private function getConfig(): array
    {
        $key    = 'ventas_configuracion_' . $this->empresaId();
        $cached = Cache::get($key);
        if ($cached) return $cached;

        $row = Configuracion::where('empresa_id', $this->empresaId())->first();
        return $row?->config ?? [];
    }

    private function saveFacturacion(array $patch): void
    {
        $config              = $this->getConfig();
        $config['facturacion'] = array_merge($config['facturacion'] ?? [], $patch);

        Configuracion::updateOrCreate(
            ['empresa_id' => $this->empresaId()],
            ['config'     => $config]
        );

        Cache::forever('ventas_configuracion_' . $this->empresaId(), $config);
    }

    private function facturacion(): array
    {
        return ($this->getConfig())['facturacion'] ?? [];
    }

    private function orgKey(): string
    {
        $f   = $this->facturacion();
        $env = $f['ambiente'] ?? 'test';
        return $env === 'live' ? ($f['facturapi_live_key'] ?? '') : ($f['facturapi_test_key'] ?? '');
    }

    private function facturapi(): FacturapiService
    {
        return new FacturapiService();
    }

    // ─── Setup ───────────────────────────────────────────────────────────────

    public function setup(Request $request)
    {
        if (!config('services.facturapi.user_key')) {
            return response()->json(['message' => 'FACTURAPI_USER_KEY no configurado en el servidor'], 500);
        }

        $config  = $this->getConfig();
        $empresa = $config['empresa'] ?? [];

        if (empty($empresa['nombre'])) {
            return response()->json(['message' => 'Configura el nombre del negocio en la pestaña Empresa primero'], 422);
        }

        $facturacion = $config['facturacion'] ?? [];

        // Si ya tiene org, solo devolver estado actual
        if (!empty($facturacion['facturapi_org_id'])) {
            return response()->json([
                'message'    => 'Organización ya existente',
                'org_id'     => $facturacion['facturapi_org_id'],
                'csd_subido' => (bool) ($facturacion['csd_subido'] ?? false),
            ]);
        }

        try {
            $result = $this->facturapi()->crearOrganizacion($empresa['nombre']);

            $this->saveFacturacion([
                'facturapi_org_id'   => $result['org_id'],
                'facturapi_live_key' => $result['live_key'],
                'facturapi_test_key' => $result['test_key'],
                'csd_subido'         => false,
            ]);

            return response()->json([
                'message' => 'Organización creada en Facturapi',
                'org_id'  => $result['org_id'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function uploadCsd(Request $request)
    {
        $request->validate([
            'cer'      => 'required|string',
            'key'      => 'required|string',
            'password' => 'required|string',
        ]);

        $f = $this->facturacion();

        if (empty($f['facturapi_org_id'])) {
            return response()->json(['message' => 'Crea la organización en Facturapi primero'], 422);
        }

        try {
            $this->facturapi()->subirCsd(
                $f['facturapi_org_id'],
                $request->cer,
                $request->key,
                $request->password
            );

            $this->saveFacturacion(['csd_subido' => true]);

            return response()->json(['message' => 'CSD registrado correctamente']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function test()
    {
        $key = $this->orgKey();

        if (!$key) {
            return response()->json(['message' => 'Completa el setup de Facturapi primero'], 422);
        }

        try {
            $this->facturapi()->testOrg($key);
            return response()->json(['message' => 'Conexión con Facturapi exitosa']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ─── Facturación ─────────────────────────────────────────────────────────

    public function facturar(Request $request, Venta $venta)
    {
        if ($venta->cfdi_uuid) {
            return response()->json(['message' => 'Esta venta ya tiene CFDI', 'cfdi_uuid' => $venta->cfdi_uuid], 422);
        }

        if ($venta->estado === 'cancelada') {
            return response()->json(['message' => 'No se puede facturar una venta cancelada'], 422);
        }

        $config      = $this->getConfig();
        $facturacion = $config['facturacion'] ?? [];

        if (empty($facturacion['activa'])) {
            return response()->json(['message' => 'Facturación no activada en Configuración'], 422);
        }

        if (empty($facturacion['csd_subido'])) {
            return response()->json(['message' => 'Sube el CSD antes de facturar'], 422);
        }

        $orgKey = $this->orgKey();
        if (!$orgKey) {
            return response()->json(['message' => 'Configuración de Facturapi incompleta'], 422);
        }

        $receptor = $request->input('receptor', []);
        $venta->load('items.producto', 'cliente');

        try {
            $fp      = $this->facturapi();
            $payload = $this->buildPayload($venta, $config, $receptor);
            $invoice = $fp->crearFactura($orgKey, $payload);

            $uuid = $invoice['uuid'] ?? null;
            $id   = $invoice['id']   ?? null;

            $xml = $id ? $fp->descargarXml($orgKey, $id) : null;

            $venta->update([
                'cfdi_uuid'         => $uuid,
                'cfdi_facturapi_id' => $id,
                'cfdi_xml'          => $xml,
                'cfdi_status'       => 'timbrado',
                'cfdi_receptor'     => $receptor ?: null,
            ]);

            return response()->json(['message' => 'CFDI generado', 'cfdi_uuid' => $uuid]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function downloadXml(Venta $venta)
    {
        if (!$venta->cfdi_xml) {
            return response()->json(['message' => 'Sin CFDI'], 404);
        }

        return response($venta->cfdi_xml, 200, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"CFDI_{$venta->folio}_{$venta->cfdi_uuid}.xml\"",
        ]);
    }

    public function downloadPdf(Venta $venta)
    {
        if (!$venta->cfdi_facturapi_id) {
            return response()->json(['message' => 'Sin CFDI'], 404);
        }

        $orgKey = $this->orgKey();

        try {
            $pdf = $this->facturapi()->descargarPdf($orgKey, $venta->cfdi_facturapi_id);

            return response($pdf, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"CFDI_{$venta->folio}.pdf\"",
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ─── CFDI Builder (Facturapi format) ─────────────────────────────────────

    private function buildPayload(Venta $venta, array $config, array $receptor): array
    {
        $facturacion = $config['facturacion'] ?? [];
        $tasaIva     = ($config['pos']['impuesto'] ?? 16) / 100;

        $items = $venta->items->map(function ($item) use ($tasaIva) {
            $precioConIva = round((float) $item->precio_unitario, 6);

            return [
                'product' => [
                    'description'  => mb_strtoupper($item->nombre_producto),
                    'product_key'  => $item->producto?->clave_sat        ?? '01010101',
                    'unit_key'     => $item->producto?->clave_unidad_sat ?? 'H87',
                    'unit_name'    => 'Pieza',
                    'price'        => $precioConIva,
                    'tax_included' => true,
                    'taxes'        => [['type' => 'IVA', 'rate' => $tasaIva, 'factor' => 'Tasa']],
                ],
                'quantity' => (float) $item->cantidad,
            ];
        })->values()->toArray();

        $rfc = strtoupper(trim($receptor['rfc'] ?? $venta->cliente?->rfc ?? ''));
        $esPublico = !$rfc || $rfc === 'XAXX010101000';

        $customer = $esPublico ? [
            'legal_name' => 'PUBLICO EN GENERAL',
            'rfc'        => 'XAXX010101000',
            'tax_system' => '616',
            'address'    => ['zip' => $facturacion['codigo_postal'] ?? '00000'],
        ] : [
            'legal_name' => strtoupper(trim($receptor['nombre'] ?? $venta->cliente?->nombre ?? '')),
            'rfc'        => $rfc,
            'tax_system' => $receptor['regimen_fiscal'] ?? $venta->cliente?->regimen_fiscal ?? '616',
            'address'    => ['zip' => $receptor['codigo_postal'] ?? $venta->cliente?->codigo_postal ?? '00000'],
        ];

        $folio = (int) ($facturacion['folio_actual'] ?? 1);
        $this->saveFacturacion(['folio_actual' => $folio + 1]);

        return [
            'type'         => 'I',
            'customer'     => $customer,
            'items'        => $items,
            'payment_form' => $this->mapFormaPago($venta->tipo_pago),
            'payment_method' => 'PUE',
            'use'          => $esPublico ? 'S01' : ($receptor['uso_cfdi'] ?? $venta->cliente?->uso_cfdi ?? 'G03'),
            'series'       => $facturacion['serie'] ?? 'A',
            'folio_number' => $folio,
        ];
    }

    private function mapFormaPago(string $tipo): string
    {
        return match ($tipo) {
            'efectivo' => '01',
            'tarjeta'  => '04',
            'credito'  => '99',
            default    => '99',
        };
    }
}
