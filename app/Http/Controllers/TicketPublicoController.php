<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\Cotizacion;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Venta;
use App\Services\WhatsAppService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketPublicoController extends Controller
{
    public function showVenta(string $token)
    {
        $venta = Venta::withoutGlobalScopes()
            ->where('ticket_token', $token)
            ->with(['user', 'cliente', 'items', 'pagos'])
            ->firstOrFail();

        [$config, $empresa, $ticket, $logoUrl, $documentos] = $this->resolveConfig($venta->empresa_id);

        return $this->pdf('ticket.venta', compact('venta', 'empresa', 'ticket', 'logoUrl', 'config', 'documentos'),
            'ticket-' . $venta->folio);
    }

    // HTML page with accept/reject buttons
    public function showCotizacion(string $token)
    {
        $cotizacion = Cotizacion::withoutGlobalScopes()
            ->where('ticket_token', $token)
            ->with(['cliente', 'vendedor:id,name', 'items.producto'])
            ->firstOrFail();

        [$config, $empresa, , $logoUrl, $documentos] = $this->resolveConfig($cotizacion->empresa_id);

        return view('ticket.cotizacion_publica', compact('cotizacion', 'empresa', 'logoUrl', 'config', 'documentos', 'token'));
    }

    // PDF download for cotizacion
    public function downloadCotizacion(string $token)
    {
        $cotizacion = Cotizacion::withoutGlobalScopes()
            ->where('ticket_token', $token)
            ->with(['cliente', 'vendedor:id,name', 'items.producto'])
            ->firstOrFail();

        [$config, $empresa, , $logoUrl, $documentos] = $this->resolveConfig($cotizacion->empresa_id);

        return $this->pdf('ticket.cotizacion', compact('cotizacion', 'empresa', 'logoUrl', 'config', 'documentos'),
            'cotizacion-' . $cotizacion->folio);
    }

    public function aceptarCotizacion(string $token)
    {
        $cotizacion = Cotizacion::withoutGlobalScopes()
            ->where('ticket_token', $token)
            ->firstOrFail();

        $actionMessage = null;
        $accepted = false;

        DB::transaction(function () use ($cotizacion, &$actionMessage, &$accepted) {
            $locked = Cotizacion::withoutGlobalScopes()
                ->whereKey($cotizacion->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($locked->status, ['borrador', 'enviada'])) {
                $actionMessage = match($locked->status) {
                    'aceptada'  => 'Esta cotización ya fue aceptada.',
                    'rechazada' => 'Esta cotización ya fue rechazada.',
                    'vencida'   => 'Esta cotización ya venció.',
                    default     => 'No se puede modificar el estado de esta cotización.',
                };
                return;
            }

            $locked->load(['items.producto']);
            $locked->update(['status' => 'aceptada']);

            $pedido = Pedido::create([
                'empresa_id'       => $locked->empresa_id,
                'sucursal_id'      => $locked->sucursal_id,
                'cliente_id'       => $locked->cliente_id,
                'nombre_cliente'   => $locked->nombre_cliente,
                'vendedor_id'      => $locked->vendedor_id,
                'cotizacion_id'    => $locked->id,
                'fecha'            => now()->toDateString(),
                'fecha_entrega'    => $locked->fecha_vencimiento,
                'subtotal'         => $locked->subtotal,
                'descuento'        => $locked->descuento,
                'impuesto_pct'     => $locked->impuesto_pct,
                'total'            => $locked->total,
                'notas'            => $locked->notas,
                'status'           => 'pendiente',
            ]);

            foreach ($locked->items as $item) {
                PedidoItem::create([
                    'pedido_id'       => $pedido->id,
                    'producto_id'     => $item->producto_id,
                    'descripcion'     => $item->descripcion,
                    'cantidad'        => $item->cantidad,
                    'precio_unitario' => $item->precio_unitario,
                    'descuento'       => $item->descuento ?? 0,
                    'subtotal'        => $item->subtotal,
                ]);
            }

            $accepted = true;
        });

        if (!$accepted) {
            return back()->with('action_msg', $actionMessage);
        }

        $cotizacion->refresh();
        $this->notificarNegocio($cotizacion, 'aceptada');

        return back()->with('action_msg', '¡Cotización aceptada! Se ha generado un pedido.');
    }

    public function rechazarCotizacion(string $token)
    {
        $cotizacion = Cotizacion::withoutGlobalScopes()
            ->where('ticket_token', $token)
            ->firstOrFail();

        $actionMessage = null;
        $rejected = false;

        DB::transaction(function () use ($cotizacion, &$actionMessage, &$rejected) {
            $locked = Cotizacion::withoutGlobalScopes()
                ->whereKey($cotizacion->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($locked->status, ['borrador', 'enviada'])) {
                $actionMessage = match($locked->status) {
                    'aceptada'  => 'Esta cotización ya fue aceptada.',
                    'rechazada' => 'Esta cotización ya fue rechazada.',
                    'vencida'   => 'Esta cotización ya venció.',
                    default     => 'No se puede modificar el estado de esta cotización.',
                };
                return;
            }

            $locked->update(['status' => 'rechazada']);
            $rejected = true;
        });

        if (!$rejected) {
            return back()->with('action_msg', $actionMessage);
        }

        $cotizacion->refresh();
        $this->notificarNegocio($cotizacion, 'rechazada');

        return back()->with('action_msg', 'Cotización rechazada.');
    }

    private function notificarNegocio(Cotizacion $cotizacion, string $accion): void
    {
        try {
            $svc = app(WhatsAppService::class);
            $config = $this->resolveConfig($cotizacion->empresa_id);
            $empresa = $config[1];
            $documentos = $config[4];

            $technicalConfig = $svc->resolveTechnicalConfig((int) $cotizacion->empresa_id);
            if (!$svc->isFeatureEnabled((int) $cotizacion->empresa_id, null, 'auto_send_quote')) return;
            if (!$svc->isConnected($technicalConfig)) return;

            // Send to empresa's configured phone number
            $publicConfig = $svc->resolvePublicConfig((int) $cotizacion->empresa_id);
            $businessPhone = $publicConfig['connected_phone_number'] ?? $publicConfig['phone_number'] ?? null;
            if (!$businessPhone) return;

            $clienteNombre = $cotizacion->cliente?->nombre ?? $cotizacion->nombre_cliente ?? 'El cliente';
            $emoji = $accion === 'aceptada' ? '✅' : '❌';
            $body = "{$emoji} *Cotización {$accion}*\n\n"
                . "📋 Folio: {$cotizacion->folio}\n"
                . "👤 Cliente: {$clienteNombre}\n"
                . "💰 Total: $" . number_format((float) $cotizacion->total, 2);

            if ($accion === 'aceptada') {
                $body .= "\n\n🎉 Se generó un pedido automáticamente.";
            }

            $svc->sendTextMessage($technicalConfig, $businessPhone, $body);
        } catch (\Throwable $e) {
            Log::warning("No se pudo notificar al negocio sobre cotizacion {$cotizacion->id}: " . $e->getMessage());
        }
    }

    public function showPedido(string $token)
    {
        $pedido = Pedido::withoutGlobalScopes()
            ->where('ticket_token', $token)
            ->with(['cliente', 'vendedor:id,name', 'items.producto'])
            ->firstOrFail();

        [$config, $empresa, , $logoUrl, $documentos] = $this->resolveConfig($pedido->empresa_id);

        return $this->pdf('ticket.pedido', compact('pedido', 'empresa', 'logoUrl', 'config', 'documentos'),
            'pedido-' . $pedido->folio);
    }

    private function pdf(string $view, array $data, string $filename): \Illuminate\Http\Response
    {
        $pdf = Pdf::loadView($view, $data)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'    => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => true,
            ]);

        return $pdf->stream($filename . '.pdf');
    }

    private function resolveConfig(int $empresaId): array
    {
        $config = Cache::remember(
            "ventas_configuracion_{$empresaId}",
            3600,
            fn () => Configuracion::where('empresa_id', $empresaId)->first()?->config ?? []
        );

        $empresa    = $config['empresa'] ?? [];
        $ticket     = $config['ticket'] ?? [];
        $documentos = $config['documentos'] ?? [];

        $logoUrl = null;
        $mostrarLogo = $documentos['mostrar_logo'] ?? true;
        if ($mostrarLogo) {
            $files = glob(storage_path("app/public/config/{$empresaId}/logo_*"));
            if (!empty($files)) {
                $path = $files[0];
                $mime = mime_content_type($path) ?: 'image/png';
                $logoUrl = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
            }
        }

        return [$config, $empresa, $ticket, $logoUrl, $documentos];
    }
}
