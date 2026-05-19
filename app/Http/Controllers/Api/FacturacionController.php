<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Models\Venta;
use App\Services\FacturapiService;
use App\Traits\EnviaCorreosTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FacturacionController extends Controller
{
    use EnviaCorreosTrait;
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
        return $env === 'live'
            ? ($f['facturapi_live_key'] ?? '')
            : ($f['facturapi_test_key'] ?? '');
    }

    private function facturapi(): FacturapiService
    {
        return new FacturapiService();
    }

    /** Obtiene y guarda las API keys de la org desde Facturapi. */
    private function fetchAndSaveOrgKeys(string $orgId): void
    {
        $fp = $this->facturapi();

        $testKey = '';
        $liveKey = '';

        try { $testKey = $fp->getTestApiKey($orgId); } catch (\Exception $e) {}
        try { $liveKey = $fp->getLiveApiKey($orgId);  } catch (\Exception $e) {}

        $this->saveFacturacion([
            'facturapi_test_key' => $testKey,
            'facturapi_live_key' => $liveKey,
        ]);
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
            $fp    = $this->facturapi();
            $orgId = $fp->crearOrganizacion($empresa['nombre']);

            $this->saveFacturacion([
                'facturapi_org_id' => $orgId,
                'csd_subido'       => false,
            ]);

            // Obtener y guardar las API keys de la nueva org
            $this->fetchAndSaveOrgKeys($orgId);

            // Actualizar datos legales si la empresa tiene RFC y CP configurados
            $rfc = strtoupper(trim($empresa['rfc'] ?? ''));
            $cp  = $facturacion['codigo_postal'] ?? '';
            if ($cp) {
                $fp->actualizarLegalOrg($orgId, [
                    'name'       => mb_strtoupper($empresa['nombre']),
                    'tax_system' => $facturacion['regimen_fiscal'] ?? '601',
                    'address'    => ['zip' => $cp],
                ]);
            }

            return response()->json([
                'message' => 'Organización creada en Facturapi',
                'org_id'  => $orgId,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function actualizarLegal()
    {
        $config      = $this->getConfig();
        $empresa     = $config['empresa'] ?? [];
        $facturacion = $config['facturacion'] ?? [];

        $orgId = $facturacion['facturapi_org_id'] ?? null;
        if (!$orgId) {
            return response()->json(['message' => 'Crea la organización primero'], 422);
        }

        $rfc = strtoupper(trim($empresa['rfc'] ?? ''));
        $cp  = $facturacion['codigo_postal'] ?? '';

        if (!$rfc || !$cp) {
            return response()->json(['message' => 'Configura RFC y código postal en la pestaña Empresa y Facturación'], 422);
        }

        try {
            $this->facturapi()->actualizarLegalOrg($orgId, [
                'name'       => mb_strtoupper($empresa['nombre'] ?? ''),
                'tax_system' => $facturacion['regimen_fiscal'] ?? '601',
                'address'    => ['zip' => $cp],
            ]);

            return response()->json(['message' => 'Datos legales de la organización actualizados']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function confirmarCsd()
    {
        $f = $this->facturacion();
        if (empty($f['facturapi_org_id'])) {
            return response()->json(['message' => 'Crea la organización primero'], 422);
        }
        $this->saveFacturacion(['csd_subido' => true]);
        return response()->json(['message' => 'CSD marcado como activo']);
    }

    public function reset()
    {
        $this->saveFacturacion([
            'facturapi_org_id' => null,
            'csd_subido'       => false,
        ]);

        return response()->json(['message' => 'Configuración de Facturapi reiniciada']);
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
        $f     = $this->facturacion();
        $orgId = $f['facturapi_org_id'] ?? '';

        if (!$orgId) {
            return response()->json(['needs_reconnect' => true, 'message' => 'Configura la facturación primero.'], 422);
        }

        // Si no hay keys guardadas, obtenerlas de Facturapi
        if (!$this->orgKey()) {
            $this->fetchAndSaveOrgKeys($orgId);
        }

        $key = $this->orgKey();
        if (!$key) {
            return response()->json(['needs_reconnect' => true, 'message' => 'No se pudieron obtener las credenciales de facturación. Reconecta tu cuenta.'], 422);
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

        if (empty($facturacion['csd_subido'])) {
            return response()->json(['message' => 'Sube el CSD antes de facturar (Configuración → Facturación)'], 422);
        }

        $orgKey = $this->orgKey();
        if (!$orgKey) {
            return response()->json(['message' => 'La facturación no está configurada. Ve a Configuración → Facturación.'], 422);
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

            // Enviar CFDI por correo — prioridad: cliente_id del receptor, luego cliente de la venta
            $emailDestinatario = null;
            $clienteIdReceptor = $receptor['cliente_id'] ?? null;
            if ($clienteIdReceptor) {
                $clienteReceptor   = \App\Models\Cliente::find($clienteIdReceptor);
                $emailDestinatario = $clienteReceptor?->email;
            }
            if (!$emailDestinatario) {
                $emailDestinatario = $venta->cliente?->email;
            }
            // Email manual capturado en el form cuando no hay cliente registrado
            if (!$emailDestinatario && !empty($receptor['email'])) {
                $emailDestinatario = $receptor['email'];
            }
            // Guardar email en cfdi_receptor para reenvíos futuros
            if ($emailDestinatario && !empty($receptor)) {
                $receptor['email'] = $emailDestinatario;
            }

            $emailEnviado = false;
            $emailError   = null;

            Log::info('CFDI email debug', [
                'venta_id'          => $venta->id,
                'cliente_id_venta'  => $venta->cliente_id,
                'cliente_id_receptor' => $clienteIdReceptor,
                'email_destinatario' => $emailDestinatario,
                'uuid'              => $uuid,
            ]);

            if ($emailDestinatario && $uuid) {
                try {
                    $pdfContent = null;
                    if ($id) {
                        try { $pdfContent = $fp->descargarPdf($orgKey, $id); } catch (\Exception $e) {
                            Log::warning('Error descargando PDF para email CFDI: ' . $e->getMessage());
                        }
                    }
                    $this->enviarCfdiEmail($venta, $receptor, $uuid, $xml ?? '', $pdfContent, $emailDestinatario);
                    $emailEnviado = true;
                } catch (\Exception $e) {
                    $emailError = $e->getMessage();
                    Log::warning('Error enviando CFDI por correo: ' . $e->getMessage());
                }
            }

            return response()->json([
                'message'       => 'CFDI generado',
                'cfdi_uuid'     => $uuid,
                'email_enviado' => $emailEnviado,
                'email_destino' => $emailDestinatario,
                'email_error'   => $emailError,
            ]);
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

    public function reenviarEmail(Request $request, Venta $venta)
    {
        if (!$venta->cfdi_uuid) {
            return response()->json(['message' => 'Esta venta no tiene CFDI generado'], 422);
        }

        $email = $request->input('email')
            ?? ($venta->cfdi_receptor['email'] ?? null)
            ?? $venta->cliente?->email;

        if (!$email) {
            return response()->json(['message' => 'Proporciona un correo de destino'], 422);
        }

        $venta->load('items.producto', 'cliente');
        $receptor = $venta->cfdi_receptor ?? [];

        $pdfContent = null;
        if ($venta->cfdi_facturapi_id) {
            try { $pdfContent = $this->facturapi()->descargarPdf($this->orgKey(), $venta->cfdi_facturapi_id); } catch (\Exception $e) {}
        }

        try {
            $this->enviarCfdiEmail($venta, $receptor, $venta->cfdi_uuid, $venta->cfdi_xml ?? '', $pdfContent, $email);
            return response()->json(['message' => "CFDI reenviado a {$email}"]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // ─── Email CFDI ──────────────────────────────────────────────────────────

    private function enviarCfdiEmail(Venta $venta, array $receptor, string $uuid, string $xml, ?string $pdf, string $email): void
    {

        $html = $this->buildEmailWrapper(
            $this->buildCfdiBodyHtml($venta, $receptor, $uuid),
            'Comprobante Fiscal CFDI 4.0',
            ['intro' => 'Adjuntamos el XML y PDF de su Comprobante Fiscal Digital (CFDI 4.0). Guarde estos archivos para sus registros contables.']
        );

        $cc    = $this->getCorreosCC();
        $folio = $venta->folio;

        Mail::html($html, function ($message) use ($email, $cc, $folio, $uuid, $xml, $pdf) {
            $message->to($email)->subject("Factura CFDI {$folio}");
            if (!empty($cc)) $message->cc($cc);
            if ($xml) {
                $message->attachData($xml, "CFDI_{$folio}_{$uuid}.xml", ['mime' => 'application/xml']);
            }
            if ($pdf) {
                $message->attachData($pdf, "CFDI_{$folio}.pdf", ['mime' => 'application/pdf']);
            }
        });
    }

    private function buildCfdiBodyHtml(Venta $venta, array $receptor, string $uuid): string
    {
        $color         = $this->getNotifConfig()['color_primario'] ?? '#2563eb';
        $nombreCliente = htmlspecialchars(!empty($receptor['nombre']) ? $receptor['nombre'] : ($venta->cliente?->nombre ?? 'Público en General'));
        $rfc           = htmlspecialchars($receptor['rfc'] ?? $venta->cliente?->rfc ?? 'XAXX010101000');
        $fecha         = $venta->created_at->format('d/m/Y');

        $itemsHtml = '';
        foreach ($venta->items as $item) {
            $itemsHtml .= sprintf(
                '<tr><td style="padding:8px;border-bottom:1px solid #f3f4f6">%s</td>
                 <td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:center">%s</td>
                 <td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:right">$%s</td>
                 <td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:600">$%s</td></tr>',
                htmlspecialchars($item->nombre_producto),
                number_format((float) $item->cantidad, 2),
                number_format((float) $item->precio_unitario, 2),
                number_format((float) $item->subtotal, 2)
            );
        }

        $total = number_format((float) $venta->total, 2);

        $uuidHtml = '<div style="margin-top:16px;padding:12px;background:#f0f9ff;border-radius:8px;border:1px solid #bae6fd">
            <p style="margin:0 0 4px;font-size:11px;color:#0284c7;text-transform:uppercase;letter-spacing:.05em">Folio Fiscal (UUID)</p>
            <p style="margin:0;font-size:12px;font-family:monospace;color:#0c4a6e;word-break:break-all">' . htmlspecialchars($uuid) . '</p>
        </div>';

        $adjuntosHtml = '<div style="margin-top:12px;padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
            <p style="margin:0 0 4px;font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em">Archivos adjuntos</p>
            <p style="margin:0;font-size:13px;color:#374151">El XML y PDF del CFDI se encuentran adjuntos a este correo.</p>
        </div>';

        return '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">Cliente</p>
    <p style="margin:0;font-weight:600;color:#111827">' . $nombreCliente . '</p>
  </div>
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">RFC</p>
    <p style="margin:0;font-weight:600;color:' . $color . '">' . $rfc . '</p>
  </div>
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">Folio venta</p>
    <p style="margin:0;font-weight:600;color:#111827">' . htmlspecialchars($venta->folio) . '</p>
  </div>
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">Fecha</p>
    <p style="margin:0;font-weight:600;color:#111827">' . $fecha . '</p>
  </div>
</div>
<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:12px">
  <thead><tr style="background:#f3f4f6">
    <th style="padding:10px 8px;text-align:left;font-size:11px;color:#6b7280;text-transform:uppercase">Descripción</th>
    <th style="padding:10px 8px;text-align:center;font-size:11px;color:#6b7280;text-transform:uppercase">Cant.</th>
    <th style="padding:10px 8px;text-align:right;font-size:11px;color:#6b7280;text-transform:uppercase">P.U.</th>
    <th style="padding:10px 8px;text-align:right;font-size:11px;color:#6b7280;text-transform:uppercase">Subtotal</th>
  </tr></thead>
  <tbody>' . $itemsHtml . '</tbody>
</table>
<table width="300" cellpadding="0" cellspacing="0" style="margin-left:auto;margin-bottom:12px">
  <tr>
    <td style="text-align:right;padding:8px;font-size:16px;font-weight:700;color:' . $color . ';border-top:2px solid #e5e7eb">TOTAL:</td>
    <td style="text-align:right;padding:8px;font-size:16px;font-weight:700;color:' . $color . ';border-top:2px solid #e5e7eb">$' . $total . '</td>
  </tr>
</table>' . $uuidHtml . $adjuntosHtml;
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

        $rfc       = strtoupper(trim($receptor['rfc'] ?? $venta->cliente?->rfc ?? ''));
        $esPublico = !$rfc || $rfc === 'XAXX010101000' || $rfc === 'XEXX010101000';
        // Persona moral = RFC 12 chars, física = 13 chars
        $esMoral   = !$esPublico && strlen($rfc) === 12;

        $defaultRegimen = $esMoral ? '601' : '616';
        $defaultUso     = 'S01'; // Sin efectos fiscales — válido para ambos tipos

        $customer = $esPublico ? [
            'legal_name' => 'PUBLICO EN GENERAL',
            'tax_id'     => 'XAXX010101000',
            'tax_system' => '616',
            'address'    => ['zip' => $facturacion['codigo_postal'] ?? '00000'],
        ] : [
            'legal_name' => strtoupper(trim($receptor['nombre'] ?? $venta->cliente?->nombre ?? '')),
            'tax_id'     => $rfc,
            'tax_system' => $receptor['regimen_fiscal'] ?? $venta->cliente?->regimen_fiscal ?? $defaultRegimen,
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
            'use'          => $esPublico ? 'S01' : ($receptor['uso_cfdi'] ?? $venta->cliente?->uso_cfdi ?? $defaultUso),
            'series'       => $facturacion['serie'] ?? 'A',
            'folio_number' => $folio,
        ];
    }

    public function cancelarCfdi(Request $request, Venta $venta)
    {
        if (!$venta->cfdi_uuid || !$venta->cfdi_facturapi_id) {
            return response()->json(['message' => 'Esta venta no tiene CFDI generado'], 422);
        }

        if ($venta->cfdi_status === 'cancelado') {
            return response()->json(['message' => 'El CFDI ya está cancelado'], 422);
        }

        $orgKey = $this->orgKey();
        if (!$orgKey) {
            return response()->json(['message' => 'Facturación no configurada'], 422);
        }

        try {
            $this->facturapi()->cancelarFactura($orgKey, $venta->cfdi_facturapi_id, $request->input('motive', '02'));

            $venta->update(['cfdi_status' => 'cancelado']);

            return response()->json(['message' => 'CFDI cancelado correctamente']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
