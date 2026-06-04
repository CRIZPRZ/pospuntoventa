<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\FacturaSuscripcionMail;
use App\Models\SuperAdminConfig;
use App\Models\SuscripcionFactura;
use App\Services\FacturapiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Stripe;
use Stripe\Invoice;

class SuscripcionFacturaController extends Controller
{
    public function __construct(private FacturapiService $facturapi) {}

    private function getSuperAdminApiKey(): string
    {
        $ambiente = SuperAdminConfig::get('fiscal_ambiente', 'test');
        $key      = $ambiente === 'live'
            ? SuperAdminConfig::get('fiscal_facturapi_live_key')
            : SuperAdminConfig::get('fiscal_facturapi_test_key');

        if (! $key) abort(422, 'El sistema de facturación no está configurado aún. Contacta al soporte.');
        return $key;
    }

    /**
     * POST /api/billing/invoices/{stripeInvoiceId}/solicitar-factura
     * El tenant solicita CFDI por su pago de suscripción.
     */
    public function solicitar(Request $request, string $stripeInvoiceId)
    {
        $data = $request->validate([
            'receptor_rfc'             => 'required|string|max:13',
            'receptor_nombre'          => 'required|string|max:255',
            'receptor_codigo_postal'   => 'required|string|max:10',
            'receptor_regimen_fiscal'  => 'required|string|max:10',
            'receptor_uso_cfdi'        => 'required|string|max:10',
        ]);

        $empresa = $request->user()->empresa;

        // Verificar que este invoice pertenece a esta empresa
        if ($empresa->stripe_customer_id) {
            $stripeKey = config('services.stripe.secret');
            if ($stripeKey) {
                Stripe::setApiKey($stripeKey);
                try {
                    $inv = Invoice::retrieve($stripeInvoiceId);
                    if ($inv->customer !== $empresa->stripe_customer_id) {
                        abort(403, 'Invoice no pertenece a tu empresa');
                    }
                } catch (\Throwable) {
                    // Si Stripe falla, continuar — el invoice_id lo proveyó el propio tenant
                }
            }
        }

        // Verificar no facturado ya
        $existente = SuscripcionFactura::where('stripe_invoice_id', $stripeInvoiceId)
            ->where('empresa_id', $empresa->id)
            ->where('cfdi_status', 'timbrado')
            ->first();

        if ($existente) {
            return response()->json([
                'message' => 'Este pago ya tiene CFDI generado',
                'factura' => $existente,
            ], 422);
        }

        // Validar config fiscal del superadmin
        $csdSubido = SuperAdminConfig::get('fiscal_csd_subido');
        if (! $csdSubido) {
            abort(422, 'El sistema de facturación aún no está disponible. Intenta más tarde.');
        }

        $apiKey = $this->getSuperAdminApiKey();

        // Datos del emisor (superadmin)
        $rfcEmisor      = SuperAdminConfig::get('fiscal_rfc');
        $razonEmisor    = SuperAdminConfig::get('fiscal_razon_social');
        $regimenEmisor  = SuperAdminConfig::get('fiscal_regimen_fiscal');
        $cpEmisor       = SuperAdminConfig::get('fiscal_codigo_postal');
        $serie          = SuperAdminConfig::get('fiscal_serie', 'SS');

        if (! $rfcEmisor || ! $razonEmisor) {
            abort(422, 'Configuración fiscal del emisor incompleta. Contacta al soporte.');
        }

        // Generar CFDI vía Facturapi usando la org del superadmin
        $payload = [
            'type'     => 'I',
            'customer' => [
                'legal_name' => $data['receptor_nombre'],
                'tax_id'     => $data['receptor_rfc'],
                'tax_system' => $data['receptor_regimen_fiscal'],
                'address'    => ['zip' => $data['receptor_codigo_postal']],
                'email'      => $empresa->email,
            ],
            'use'      => $data['receptor_uso_cfdi'],
            'series'   => $serie,
            'items'    => [[
                'quantity'    => 1,
                'product'     => [
                    'description'  => 'Suscripción Ventas POS — ' . ($empresa->plan?->nombre ?? 'Plan'),
                    'product_key'  => '81161500', // SAT: servicios de tecnología de información
                    'unit_key'     => 'E48',      // SAT: unidad de servicio
                    'price'        => $this->getInvoiceAmount($stripeInvoiceId),
                    'tax_included' => true,
                ],
            ]],
            'payment_form' => '28', // SPEI / tarjeta débito/crédito electrónico
        ];

        $facturapiResp = $this->facturapi->crearFactura($apiKey, $payload);

        // Guardar datos del receptor para facturas futuras
        $empresa->update(['datos_facturacion' => $data]);

        $factura = SuscripcionFactura::create([
            'empresa_id'         => $empresa->id,
            'stripe_invoice_id'  => $stripeInvoiceId,
            'cfdi_uuid'          => $facturapiResp['uuid'] ?? null,
            'cfdi_facturapi_id'  => $facturapiResp['id'] ?? null,
            'cfdi_xml'           => $facturapiResp['cfdi_xml'] ?? null,
            'cfdi_status'        => 'timbrado',
            'receptor'           => $data,
            'monto'              => $this->getInvoiceAmount($stripeInvoiceId),
            'moneda'             => 'MXN',
            'concepto'           => 'Suscripción Ventas POS — ' . ($empresa->plan?->nombre ?? ''),
        ]);

        // Enviar email con XML + PDF adjuntos
        try {
            $xmlContent = $factura->cfdi_xml ?? $this->facturapi->descargarXml($apiKey, $factura->cfdi_facturapi_id);
            $pdfRaw     = $this->facturapi->descargarPdf($apiKey, $factura->cfdi_facturapi_id);
            $pdfB64     = base64_encode($pdfRaw); // evita error de UTF-8 al serializar en queue

            $receptorEmail = $empresa->email;
            if ($receptorEmail) {
                Mail::to($receptorEmail)
                    ->queue(new FacturaSuscripcionMail($factura, $xmlContent, $pdfB64));
            }
        } catch (\Throwable $e) {
            Log::error("Error enviando mail factura suscripcion #{$factura->id}: {$e->getMessage()}");
        }

        return response()->json([
            'message' => 'CFDI generado correctamente',
            'factura' => $factura,
        ]);
    }

    /**
     * POST /api/billing/invoices/{stripeInvoiceId}/auto-facturar
     * Genera CFDI usando los datos de facturación guardados de la empresa.
     */
    public function autoFacturar(Request $request, string $stripeInvoiceId)
    {
        $empresa = $request->user()->empresa;

        if (! $empresa->datos_facturacion) {
            return response()->json(['message' => 'No hay datos de facturación guardados'], 422);
        }

        // Reusar el mismo flujo de solicitar() con los datos guardados
        $request->merge($empresa->datos_facturacion);
        return $this->solicitar($request, $stripeInvoiceId);
    }

    /**
     * GET /api/billing/invoices/{stripeInvoiceId}/factura
     * Verifica si ya existe CFDI para este invoice.
     */
    public function show(Request $request, string $stripeInvoiceId)
    {
        $empresa = $request->user()->empresa;
        $factura = SuscripcionFactura::where('stripe_invoice_id', $stripeInvoiceId)
            ->where('empresa_id', $empresa->id)
            ->first();

        return response()->json(['data' => $factura]);
    }

    /**
     * GET /api/billing/invoices/{stripeInvoiceId}/factura/xml
     */
    public function downloadXml(Request $request, string $stripeInvoiceId)
    {
        $empresa = $request->user()->empresa;
        $factura = SuscripcionFactura::where('stripe_invoice_id', $stripeInvoiceId)
            ->where('empresa_id', $empresa->id)
            ->firstOrFail();

        if (! $factura->cfdi_xml) abort(404, 'XML no disponible');

        return response($factura->cfdi_xml, 200, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"cfdi_{$factura->cfdi_uuid}.xml\"",
        ]);
    }

    /**
     * GET /api/billing/invoices/{stripeInvoiceId}/factura/pdf
     */
    public function downloadPdf(Request $request, string $stripeInvoiceId)
    {
        $empresa = $request->user()->empresa;
        $factura = SuscripcionFactura::where('stripe_invoice_id', $stripeInvoiceId)
            ->where('empresa_id', $empresa->id)
            ->firstOrFail();

        if (! $factura->cfdi_facturapi_id) abort(404, 'PDF no disponible');

        $apiKey = $this->getSuperAdminApiKey();
        $pdf    = $this->facturapi->descargarPdf($apiKey, $factura->cfdi_facturapi_id);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"cfdi_{$factura->cfdi_uuid}.pdf\"",
        ]);
    }

    private function getInvoiceAmount(string $stripeInvoiceId): float
    {
        $stripeKey = config('services.stripe.secret');
        if (! $stripeKey) return 0;

        try {
            Stripe::setApiKey($stripeKey);
            $inv = Invoice::retrieve($stripeInvoiceId);
            return $inv->amount_paid / 100;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * POST /api/superadmin/empresas/{empresa}/facturar-manual
     * Genera CFDI para empresa con plan manual y envía email.
     */
    public function facturarManual(Request $request, \App\Models\Empresa $empresa)
    {
        $data = $request->validate([
            'monto'               => 'required|numeric|min:0.01',
            'concepto'            => 'required|string|max:255',
            'receptor'            => 'required|array',
            'receptor.rfc'        => 'required|string|max:20',
            'receptor.nombre'     => 'required|string|max:255',
            'receptor.codigo_postal' => 'required|string|max:10',
            'receptor.regimen_fiscal' => 'required|string|max:10',
            'receptor.uso_cfdi'   => 'required|string|max:10',
            'receptor.email'      => 'required|email',
        ]);

        $apiKey = $this->getSuperAdminApiKey();

        $payload = [
            'type'     => 'ingreso',
            'use'      => $data['receptor']['uso_cfdi'],
            'customer' => [
                'legal_name'   => $data['receptor']['nombre'],
                'rfc'          => $data['receptor']['rfc'],
                'tax_id'       => $data['receptor']['rfc'],
                'fiscal_regime'=> $data['receptor']['regimen_fiscal'],
                'zip'          => $data['receptor']['codigo_postal'],
                'email'        => $data['receptor']['email'],
            ],
            'items' => [[
                'quantity'    => 1,
                'product'     => [
                    'description'  => $data['concepto'],
                    'product_key'  => '80141600',
                    'unit_key'     => 'E48',
                    'unit_name'    => 'Servicio',
                    'price'        => (float) $data['monto'],
                    'tax_included' => true,
                    'taxes'        => [['type' => 'IVA', 'rate' => 0.16]],
                ],
            ]],
            'payment_form' => '03',
        ];

        $invoice = $this->facturapi->crearFactura($apiKey, $payload);

        $factura = SuscripcionFactura::create([
            'empresa_id'        => $empresa->id,
            'stripe_invoice_id' => null,
            'cfdi_uuid'         => $invoice['uuid'] ?? null,
            'cfdi_facturapi_id' => $invoice['id']   ?? null,
            'cfdi_xml'          => $invoice['cfdi_xml'] ?? null,
            'cfdi_status'       => 'timbrado',
            'receptor'          => $data['receptor'],
            'monto'             => (float) $data['monto'],
            'moneda'            => 'MXN',
            'concepto'          => $data['concepto'],
        ]);

        try {
            $xmlContent = $factura->cfdi_xml ?? $this->facturapi->descargarXml($apiKey, $factura->cfdi_facturapi_id);
            $pdfRaw     = $this->facturapi->descargarPdf($apiKey, $factura->cfdi_facturapi_id);
            $pdfB64     = base64_encode($pdfRaw);

            Mail::to($data['receptor']['email'])
                ->queue(new FacturaSuscripcionMail($factura, $xmlContent, $pdfB64));
        } catch (\Throwable $e) {
            Log::error("Error enviando mail factura manual empresa #{$empresa->id}: {$e->getMessage()}");
        }

        return response()->json([
            'message' => 'CFDI generado y enviado a ' . $data['receptor']['email'],
            'factura' => $factura,
        ]);
    }
}
