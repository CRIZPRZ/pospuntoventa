<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Models\Venta;
use App\Models\Cliente;
use App\Services\FacturapiService;
use App\Services\WhatsAppService;
use App\Traits\EnviaCorreosTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    private function extractCsdVigencia(string $cerBase64): ?string
    {
        try {
            $tmp = tempnam(sys_get_temp_dir(), 'cer_');
            file_put_contents($tmp, base64_decode($cerBase64));
            $output = [];
            exec('openssl x509 -inform DER -in ' . escapeshellarg($tmp) . ' -noout -enddate 2>&1', $output);
            @unlink($tmp);
            // notAfter=May  9 06:00:00 2028 GMT
            $line = implode('', $output);
            if (preg_match('/notAfter=(.+)/i', $line, $m)) {
                $date = \DateTime::createFromFormat('M  j H:i:s Y T', trim($m[1]))
                    ?: \DateTime::createFromFormat('M j H:i:s Y T', trim($m[1]));
                return $date ? $date->format('Y-m-d') : trim($m[1]);
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
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

    private function empresa(): ?\App\Models\Empresa
    {
        return \App\Models\Empresa::find($this->empresaId());
    }

    private function pac(): \App\Services\Pac\PacContract
    {
        return \App\Services\Pac\PacManager::for($this->empresa());
    }

    /** Construye el contexto del emisor (datos + credenciales resueltas) para el PAC. */
    private function emisorCtx(): array
    {
        $config  = $this->getConfig();
        $empresa = $config['empresa'] ?? [];
        $f       = $config['facturacion'] ?? [];

        return [
            'ambiente'       => ($f['ambiente'] ?? 'test') === 'live' ? 'live' : 'test',
            'rfc'            => strtoupper(trim($empresa['rfc'] ?? '')),
            'nombre'         => $empresa['nombre'] ?? 'Mi Empresa',
            // nombre_sat: nombre exacto del SAT extraído del .cer al subir CSD (Facturama lo requiere)
            'nombre_sat'     => $f['nombre_sat'] ?? null,
            'regimen_fiscal' => $f['regimen_fiscal'] ?? '601',
            'codigo_postal'  => $f['codigo_postal'] ?? '',
            'org_id'         => $f['facturapi_org_id'] ?? null,
            'org_key'        => $this->orgKey(),
        ];
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
        $config  = $this->getConfig();
        $empresa = $config['empresa'] ?? [];

        if (empty($empresa['nombre'])) {
            return response()->json(['message' => 'Configura el nombre del negocio en la pestaña Empresa primero'], 422);
        }

        $facturacion = $config['facturacion'] ?? [];
        $pac         = $this->pac();

        // Facturama: el emisor existe al cargar su CSD — no hay organización que crear.
        if ($pac->key() === 'facturama') {
            if (empty(strtoupper(trim($empresa['rfc'] ?? '')))) {
                return response()->json(['message' => 'Captura el RFC del negocio en la pestaña Empresa antes de continuar'], 422);
            }
            $this->saveFacturacion(['emisor_registrado' => true]);
            return response()->json([
                'message'    => 'Emisor listo. Sube tu CSD para comenzar a facturar.',
                'csd_subido' => (bool) ($facturacion['csd_subido'] ?? false),
            ]);
        }

        // Facturapi: crear organización (o devolver la existente).
        if (!config('services.facturapi.user_key')) {
            return response()->json(['message' => 'FACTURAPI_USER_KEY no configurado en el servidor'], 500);
        }

        if (!empty($facturacion['facturapi_org_id'])) {
            return response()->json([
                'message'    => 'Organización ya existente',
                'org_id'     => $facturacion['facturapi_org_id'],
                'csd_subido' => (bool) ($facturacion['csd_subido'] ?? false),
            ]);
        }

        try {
            $result = $pac->setup($this->emisorCtx());
            $orgId  = $result['org_id'] ?? '';

            $this->saveFacturacion([
                'facturapi_org_id' => $orgId,
                'csd_subido'       => false,
            ]);

            $this->fetchAndSaveOrgKeys($orgId);

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

        $pac = $this->pac();
        $ctx = $this->emisorCtx();

        // Facturapi requiere org creada previamente
        if ($pac->key() === 'facturapi') {
            $f = $this->facturacion();
            if (empty($f['facturapi_org_id'])) {
                return response()->json(['message' => 'Crea la organización en Facturapi primero'], 422);
            }
        }

        if (empty($ctx['rfc'])) {
            return response()->json(['message' => 'Configura el RFC del negocio antes de subir el CSD'], 422);
        }

        try {
            $nombreSat  = null;
            $csdVigencia = null;

            if (method_exists($pac, 'extractNombreFromCer')) {
                $nombreSat = $pac->extractNombreFromCer($request->cer);
            }
            $csdVigencia = $this->extractCsdVigencia($request->cer);

            $pac->subirCsd($ctx, $request->cer, $request->key, $request->password);

            $saveData = ['csd_subido' => true];
            if ($nombreSat)   $saveData['nombre_sat']   = $nombreSat;
            if ($csdVigencia) $saveData['csd_vigencia'] = $csdVigencia;
            $this->saveFacturacion($saveData);

            return response()->json([
                'message'      => 'CSD registrado correctamente',
                'nombre_sat'   => $nombreSat,
                'csd_vigencia' => $csdVigencia,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function test()
    {
        $pac = $this->pac();
        $ctx = $this->emisorCtx();

        // Facturapi: requiere org + keys
        if ($pac->key() === 'facturapi') {
            $f     = $this->facturacion();
            $orgId = $f['facturapi_org_id'] ?? '';

            if (!$orgId) {
                return response()->json(['needs_reconnect' => true, 'message' => 'Configura la facturación primero.'], 422);
            }

            if (!$this->orgKey()) {
                $this->fetchAndSaveOrgKeys($orgId);
            }

            if (!$this->orgKey()) {
                return response()->json(['needs_reconnect' => true, 'message' => 'No se pudieron obtener las credenciales de facturación. Reconecta tu cuenta.'], 422);
            }
        }

        try {
            $pac->test($ctx);
            return response()->json(['message' => 'Conexión con el PAC exitosa']);
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

        // Facturapi: requiere org key
        $pac = $this->pac();
        if ($pac->key() === 'facturapi' && !$this->orgKey()) {
            return response()->json(['message' => 'La facturación no está configurada. Ve a Configuración → Facturación.'], 422);
        }

        $receptor = $request->input('receptor', []);
        $venta->load('items.producto', 'cliente');

        // ── Créditos atómicos (race-condition-safe) ───────────────────────────
        $usarExtra       = false;
        $usarCredito     = false;
        $costoTimbre     = 0;
        $isSuperadmin    = $request->user()->is_superadmin;

        if (!$isSuperadmin) {
            try {
                DB::transaction(function () use ($request, &$usarExtra, &$usarCredito, &$costoTimbre) {
                    $empresa = \App\Models\Empresa::lockForUpdate()->find($this->empresaId());
                    if (!$empresa) throw new \Exception('Empresa no encontrada');

                    $esCustom         = ($empresa->plan?->tipo ?? '') === 'manual';
                    $timbresIncluidos = (int) ($empresa->plan?->timbres_incluidos ?? 0);

                    // Plan custom/manual → sistema de créditos en pesos
                    if ($esCustom) {
                        $credito = (float) ($empresa->credito_timbres ?? 0);
                        $costo   = (float) ($empresa->costo_timbre ?? 2);
                        if ($credito < $costo) {
                            $creditoFmt = number_format($credito, 2);
                            $costoFmt   = number_format($costo, 2);
                            throw new \Exception(
                                "Crédito insuficiente (disponible: \${$creditoFmt} MXN, necesario: \${$costoFmt} MXN). Recarga tu saldo para continuar facturando."
                            );
                        }
                        $usarCredito = true;
                        $costoTimbre = $costo;
                        return;
                    }

                    // Plan regular → cuota mensual de timbres
                    if ($timbresIncluidos === -1) return; // ilimitado

                    $timbresExtra  = (int) ($empresa->timbres_extra ?? 0);
                    $timbresUsados = \App\Models\TimbreConsumo::where('empresa_id', $empresa->id)
                        ->whereYear('created_at', now()->year)
                        ->whereMonth('created_at', now()->month)
                        ->count();

                    if ($timbresUsados < $timbresIncluidos) return; // cuota mensual disponible

                    if ($timbresExtra > 0) {
                        $usarExtra = true;
                        return;
                    }

                    throw new \Exception(
                        "Límite de timbres alcanzado ({$timbresUsados}/{$timbresIncluidos} este mes). Compra timbres adicionales en Mi Plan."
                    );
                });
            } catch (\Exception $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        // ── Timbrar ───────────────────────────────────────────────────────────
        try {
            $invoice     = $this->buildNormalizedInvoice($venta, $config, $receptor);
            $ctx         = $this->emisorCtx();
            $result      = $pac->crearFactura($ctx, $invoice);

            $uuid  = $result['uuid']   ?? null;
            $pacId = $result['pac_id'] ?? null;
            $xml   = $result['xml']    ?? null;

            $updateData = [
                'cfdi_uuid'     => $uuid,
                'cfdi_pac_id'   => $pacId,
                'cfdi_pac'      => $pac->key(),
                'cfdi_xml'      => $xml,
                'cfdi_status'   => 'timbrado',
                'cfdi_receptor' => $receptor ?: null,
            ];

            // Si la venta no tenía cliente y se timbró con uno, ligar la venta al cliente.
            $clienteIdReceptor = $receptor['cliente_id'] ?? null;
            if ($clienteIdReceptor && !$venta->cliente_id) {
                $updateData['cliente_id'] = (int) $clienteIdReceptor;
            }

            $venta->update($updateData);

            // Registrar consumo de timbre (solo tras timbrado exitoso)
            if (!$isSuperadmin) {
                \App\Models\TimbreConsumo::create([
                    'empresa_id'  => $this->empresaId(),
                    'sucursal_id' => $venta->sucursal_id,
                    'venta_id'    => $venta->id,
                    'pac'         => $pac->key(),
                    'uuid'        => $uuid,
                ]);

                if ($usarCredito && $costoTimbre > 0) {
                    \App\Models\Empresa::where('id', $this->empresaId())
                        ->where('credito_timbres', '>=', $costoTimbre)
                        ->decrement('credito_timbres', $costoTimbre);
                } elseif ($usarExtra) {
                    \App\Models\Empresa::where('id', $this->empresaId())
                        ->where('timbres_extra', '>', 0)
                        ->decrement('timbres_extra');
                }
            }

            // ── Email ─────────────────────────────────────────────────────────
            $emailDestinatario = null;
            $clienteIdReceptor = $receptor['cliente_id'] ?? null;
            if ($clienteIdReceptor) {
                $clienteReceptor   = \App\Models\Cliente::find($clienteIdReceptor);
                $emailDestinatario = $clienteReceptor?->email;
            }
            if (!$emailDestinatario) $emailDestinatario = $venta->cliente?->email;
            if (!$emailDestinatario && !empty($receptor['email'])) $emailDestinatario = $receptor['email'];
            if ($emailDestinatario && !empty($receptor)) $receptor['email'] = $emailDestinatario;

            $emailEnviado = false;
            $emailError   = null;

            if ($emailDestinatario && $uuid) {
                try {
                    $pdfContent = null;
                    if ($pacId) {
                        try { $pdfContent = $pac->descargarPdf(array_merge($ctx, ['cfdi_xml' => $xml]), $pacId); } catch (\Exception $e) {
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
        if (!$venta->cfdi_uuid) {
            return response()->json(['message' => 'Sin CFDI'], 404);
        }

        $xml = $venta->cfdi_xml;

        // Si no está en DB, intentar obtener del PAC en tiempo real.
        if (!$xml && $venta->cfdi_pac_id) {
            try {
                $pac = \App\Services\Pac\PacManager::make($venta->cfdi_pac ?? 'facturama');
                $xml = $pac->descargarXml($this->emisorCtx(), $venta->cfdi_pac_id);
                // Guardar para futuros requests.
                $venta->update(['cfdi_xml' => $xml]);
            } catch (\Exception $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        if (!$xml) {
            return response()->json(['message' => 'El XML del CFDI no está disponible'], 404);
        }

        return response($xml, 200, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"CFDI_{$venta->folio}_{$venta->cfdi_uuid}.xml\"",
        ]);
    }

    public function downloadPdf(Venta $venta)
    {
        if (!$venta->cfdi_pac_id) {
            return response()->json(['message' => 'Sin CFDI'], 404);
        }

        $pac = \App\Services\Pac\PacManager::make($venta->cfdi_pac ?? 'facturama');
        $ctx = array_merge($this->emisorCtx(), ['cfdi_xml' => $venta->cfdi_xml]);

        try {
            $pdf = $pac->descargarPdf($ctx, $venta->cfdi_pac_id);

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
        if ($venta->cfdi_pac_id) {
            $pacReenvio = \App\Services\Pac\PacManager::make($venta->cfdi_pac ?? 'facturama');
            try { $pdfContent = $pacReenvio->descargarPdf(array_merge($this->emisorCtx(), ['cfdi_xml' => $venta->cfdi_xml]), $venta->cfdi_pac_id); } catch (\Exception $e) {}
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
        $notif = $this->getNotifConfig();

        Mail::mailer($this->resolveMailer($notif))->html($html, function ($message) use ($email, $cc, $folio, $uuid, $xml, $pdf, $notif) {
            $message->to($email)->subject("Factura CFDI {$folio}");
            if (!empty($cc)) $message->cc($cc);
            if (!empty($notif['smtp_from_email'])) {
                $message->from($notif['smtp_from_email'], $notif['smtp_from_name'] ?? null);
            }
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

    // ─── CFDI Builder (formato normalizado PAC-agnóstico) ────────────────────

    private function buildNormalizedInvoice(Venta $venta, array $config, array $receptor): array
    {
        $facturacion    = $config['facturacion'] ?? [];
        $empresaCfg     = $config['empresa'] ?? [];
        $tasaIva        = ($config['pos']['impuesto'] ?? 16) / 100;

        $rfc       = strtoupper(trim($receptor['rfc'] ?? $venta->cliente?->rfc ?? ''));
        $esPublico = !$rfc || $rfc === 'XAXX010101000' || $rfc === 'XEXX010101000';
        $esMoral   = !$esPublico && strlen($rfc) === 12; // moral=12, física=13
        $defaultRegimen = $esMoral ? '601' : '616';

        $items = $venta->items->map(function ($item) use ($tasaIva) {
            return [
                'descripcion'    => mb_strtoupper($item->nombre_producto),
                'clave_sat'      => $item->producto?->clave_sat        ?? '01010101',
                'clave_unidad'   => $item->producto?->clave_unidad_sat ?? 'H87',
                'unidad'         => 'Pieza',
                'precio_con_iva' => round((float) $item->precio_unitario, 6),
                'cantidad'       => (float) $item->cantidad,
                'codigo'         => $item->producto?->codigo_barras ?? '',
            ];
        })->values()->toArray();

        $folio = (int) ($facturacion['folio_actual'] ?? 1);
        $this->saveFacturacion(['folio_actual' => $folio + 1]);

        return [
            'tipo'        => 'I',
            'serie'       => $facturacion['serie'] ?? 'A',
            'folio'       => $folio,
            'forma_pago'  => $this->mapFormaPago($venta->tipo_pago),
            'metodo_pago' => 'PUE',
            'tasa_iva'    => $tasaIva,
            'emisor'      => [
                'rfc'            => strtoupper(trim($empresaCfg['rfc'] ?? '')),
                'nombre'         => $empresaCfg['nombre'] ?? 'Mi Empresa',
                // nombre_sat: nombre exacto del SAT del CSD (Facturama exige que coincida)
                'nombre_sat'     => $facturacion['nombre_sat'] ?? null,
                'regimen_fiscal' => $facturacion['regimen_fiscal'] ?? '601',
                'codigo_postal'  => $facturacion['codigo_postal'] ?? '',
            ],
            'receptor'    => $esPublico ? [
                'rfc'            => 'XAXX010101000',
                'nombre'         => 'PUBLICO EN GENERAL',
                'regimen_fiscal' => '616',
                'codigo_postal'  => $facturacion['codigo_postal'] ?? '00000',
                'uso_cfdi'       => 'S01',
                'es_publico'     => true,
            ] : [
                'rfc'            => $rfc,
                'nombre'         => strtoupper(trim($receptor['nombre'] ?? $venta->cliente?->nombre ?? '')),
                'regimen_fiscal' => $receptor['regimen_fiscal'] ?? $venta->cliente?->regimen_fiscal ?? $defaultRegimen,
                'codigo_postal'  => $receptor['codigo_postal']  ?? $venta->cliente?->codigo_postal  ?? '00000',
                'uso_cfdi'       => $receptor['uso_cfdi']       ?? $venta->cliente?->uso_cfdi       ?? 'S01',
                'es_publico'     => false,
            ],
            'items'       => $items,
        ];
    }

    public function cancelarCfdi(Request $request, Venta $venta)
    {
        if (!$venta->cfdi_uuid || !$venta->cfdi_pac_id) {
            return response()->json(['message' => 'Esta venta no tiene CFDI generado'], 422);
        }

        if ($venta->cfdi_status === 'cancelado') {
            return response()->json(['message' => 'El CFDI ya está cancelado'], 422);
        }

        $pac    = \App\Services\Pac\PacManager::make($venta->cfdi_pac ?? 'facturama');
        $ctx    = array_merge($this->emisorCtx(), ['cfdi_xml' => $venta->cfdi_xml]);
        $motivo = $request->input('motivo', '02');
        $uuidRel= $request->input('uuid_relacionado');

        try {
            $pac->cancelarFactura($ctx, $venta->cfdi_pac_id, $motivo, $uuidRel);

            $venta->update(['cfdi_status' => 'cancelado']);

            return response()->json(['message' => 'CFDI cancelado correctamente']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function enviarCfdiWhatsApp(Request $request, Venta $venta)
    {
        $data = $request->validate([
            'cliente_id' => ['nullable', 'integer'],
            'telefono'   => ['nullable', 'string', 'max:30'],
        ]);

        if (!$venta->cfdi_uuid) {
            return response()->json(['message' => 'Esta venta no tiene CFDI timbrado.'], 422);
        }

        $telefono = null;
        $cliente = null;

        if (!empty($data['cliente_id'])) {
            $cliente = Cliente::query()->find($data['cliente_id']);
            if (!$cliente) {
                return response()->json(['message' => 'El cliente no pertenece a tu empresa.'], 422);
            }
        }

        if (!empty($data['telefono'])) {
            $telefono = $data['telefono'];
        } elseif ($cliente) {
            $telefono = $cliente?->telefono;
        }

        if (!empty($data['telefono'])) {
            if ($cliente) {
                $cliente->update(['telefono' => $telefono]);
            } elseif ($venta->cliente_id) {
                Cliente::query()
                    ->where('id', $venta->cliente_id)
                    ->update(['telefono' => $telefono]);
            }
        }

        if (!$telefono) {
            return response()->json(['message' => 'No se encontró número de teléfono.'], 422);
        }

        $svc         = app(WhatsAppService::class);
        $sucursalId  = $venta->sucursal_id ? (int) $venta->sucursal_id : null;

        if (!$svc->isFeatureEnabled((int) $venta->empresa_id, $sucursalId, 'auto_send_invoice')) {
            return response()->json(['message' => 'El envío de facturas por WhatsApp está desactivado en Configuración.'], 422);
        }

        $publicCfg   = $svc->resolvePublicConfig((int) $venta->empresa_id, $sucursalId);
        $technicalCfg = $svc->resolveTechnicalConfig((int) $venta->empresa_id, $sucursalId);

        if (!$svc->isConnected($technicalCfg)) {
            return response()->json(['message' => 'WhatsApp no está conectado.'], 422);
        }

        // Emisor: nombre fiscal de empresa + RFC desde config facturación
        $cfg          = $this->getConfig();
        $emisorNombre = $cfg['empresa']['nombre'] ?? ($technicalCfg->business_name ?? 'Tu negocio');
        $emisorRfc    = $cfg['empresa']['rfc'] ?? '';

        $zipUrl = WhatsAppService::publicUrl("/api/cfdi/{$venta->cfdi_uuid}/zip");

        $body = implode("\n", [
            "🧾 *Factura electrónica*",
            "🏢 *Emisor:* {$emisorNombre}" . ($emisorRfc ? " ({$emisorRfc})" : ''),
            '',
            "🔖 *Folio:* {$venta->folio}",
            "📋 *UUID:* {$venta->cfdi_uuid}",
            '💰 *Total:* $' . number_format((float) $venta->total, 2),
            '',
            '_Tu CFDI ha sido timbrado correctamente ante el SAT._',
        ]);

        try {
            $svc->sendTicketMessage($technicalCfg, $telefono, $body, $zipUrl, '⬇️ Descargar factura');
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Factura enviada por WhatsApp correctamente.']);
    }

    public function downloadZipPublico(string $uuid)
    {
        $venta = \App\Models\Venta::withoutGlobalScopes()
            ->with('empresa')
            ->where('cfdi_uuid', $uuid)
            ->firstOrFail();

        $pac = \App\Services\Pac\PacManager::for($venta->empresa);
        $ctx = ['cfdi_xml' => $venta->cfdi_xml, 'empresa' => $venta->empresa];

        $pdf = $pac->descargarPdf($ctx, $venta->cfdi_pac_id);

        $xml = $venta->cfdi_xml
            ?? $pac->descargarXml($ctx, $venta->cfdi_pac_id);

        $tmpFile = tempnam(sys_get_temp_dir(), 'cfdi_') . '.zip';

        $zip = new \ZipArchive();
        $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString($uuid . '.pdf', $pdf);
        $zip->addFromString($uuid . '.xml', $xml);
        $zip->close();

        return response()->download($tmpFile, $uuid . '.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function downloadPdfPublico(string $uuid)
    {
        $venta = \App\Models\Venta::withoutGlobalScopes()
            ->with('empresa')
            ->where('cfdi_uuid', $uuid)
            ->firstOrFail();

        $pac = \App\Services\Pac\PacManager::for($venta->empresa);
        $ctx = ['cfdi_xml' => $venta->cfdi_xml, 'empresa' => $venta->empresa];
        $pdf = $pac->descargarPdf($ctx, $venta->cfdi_pac_id);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $venta->cfdi_uuid . '.pdf"',
        ]);
    }

    public function downloadXmlPublico(string $uuid)
    {
        $venta = \App\Models\Venta::withoutGlobalScopes()
            ->with('empresa')
            ->where('cfdi_uuid', $uuid)
            ->firstOrFail();

        if ($venta->cfdi_xml) {
            return response($venta->cfdi_xml, 200, [
                'Content-Type'        => 'application/xml',
                'Content-Disposition' => 'attachment; filename="' . $venta->cfdi_uuid . '.xml"',
            ]);
        }

        $pac = \App\Services\Pac\PacManager::for($venta->empresa);
        $ctx = ['empresa' => $venta->empresa];
        $xml = $pac->descargarXml($ctx, $venta->cfdi_pac_id);

        return response($xml, 200, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $venta->cfdi_uuid . '.xml"',
        ]);
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
