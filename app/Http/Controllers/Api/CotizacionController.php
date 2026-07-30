<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesBySucursal;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\WhatsAppService;
use App\Traits\EnviaCorreosTrait;
use Illuminate\Http\Request;

class CotizacionController extends Controller
{
    use EnviaCorreosTrait, ScopesBySucursal;

    public function index(Request $request)
    {
        $query = $this->applySucursalScope(
            Cotizacion::with(['cliente:id,nombre', 'vendedor:id,name', 'items'])->latest()
        );

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($q) use ($search) {
                $q->where('folio', 'like', "%{$search}%")
                    ->orWhere('nombre_cliente', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('fecha', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            $query->whereDate('fecha', '<=', $request->fecha_fin);
        }

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'folio'                     => 'nullable|string|unique:cotizaciones,folio',
            'cliente_id'                => 'nullable|exists:clientes,id',
            'nombre_cliente'            => 'nullable|string|max:255',
            'email_cliente'             => 'nullable|email|max:255',
            'telefono_cliente'          => 'nullable|string|max:40',
            'vendedor_id'               => 'nullable|exists:users,id',
            'fecha'                     => 'required|date',
            'fecha_vencimiento'         => 'nullable|date',
            'status'                    => 'nullable|in:borrador,enviada,aceptada,rechazada,vencida',
            'descuento'                 => 'nullable|numeric|min:0|max:100',
            'impuesto_pct'              => 'nullable|numeric|min:0|max:100',
            'notas'                     => 'nullable|string',
            'items'                     => 'required|array|min:1',
            'items.*.producto_id'       => 'nullable|exists:productos,id',
            'items.*.descripcion'       => 'required|string|max:500',
            'items.*.cantidad'          => 'required|numeric|min:0.01',
            'items.*.precio_unitario'   => 'required|numeric|min:0',
            'items.*.descuento'         => 'nullable|numeric|min:0|max:100',
        ]);

        $cotizacion = Cotizacion::create([
            'folio'            => $data['folio'] ?? Cotizacion::generarFolio(),
            'cliente_id'       => $data['cliente_id'] ?? null,
            'nombre_cliente'   => $data['nombre_cliente'] ?? null,
            'email_cliente'    => $data['email_cliente'] ?? null,
            'telefono_cliente' => $data['telefono_cliente'] ?? null,
            'vendedor_id'      => $data['vendedor_id'] ?? auth()->id(),
            'fecha'            => $data['fecha'],
            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
            'status'           => 'borrador', // always borrador on create; client sets aceptada/rechazada via public link
            'descuento'        => $data['descuento'] ?? 0,
            'impuesto_pct'     => $data['impuesto_pct'] ?? 0,
            'notas'            => $data['notas'] ?? null,
        ]);

        foreach ($data['items'] as $itemData) {
            $descItem = $itemData['descuento'] ?? 0;
            $subtotal = CotizacionItem::calcularSubtotal(
                $itemData['cantidad'],
                $itemData['precio_unitario'],
                $descItem
            );
            CotizacionItem::create([
                'cotizacion_id'  => $cotizacion->id,
                'producto_id'    => $itemData['producto_id'] ?? null,
                'descripcion'    => $itemData['descripcion'],
                'cantidad'       => $itemData['cantidad'],
                'precio_unitario' => $itemData['precio_unitario'],
                'descuento'      => $descItem,
                'subtotal'       => $subtotal,
            ]);
        }

        $cotizacion->calcularTotales();

        $email = $cotizacion->email_cliente ?? $cotizacion->cliente?->email ?? null;
        if ($this->notifAlCrear() && $email) {
            try {
                $cotizacion->loadMissing(['cliente', 'vendedor:id,name', 'items']);
                $html = $this->buildEmailWrapper(
                    $this->buildCotizacionBodyHtml($cotizacion),
                    'Cotización'
                );
                $this->enviarConCC($email, 'Cotización ' . $cotizacion->folio, $html);
                $cotizacion->update(['status' => 'enviada']);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Auto-envío cotización falló: ' . $e->getMessage());
            }
        }

        return response()->json($cotizacion->load(['cliente', 'vendedor:id,name', 'items.producto']), 201);
    }

    public function show(Cotizacion $cotizacion)
    {
        return response()->json($cotizacion->load(['cliente', 'vendedor:id,name', 'items.producto', 'venta']));
    }

    public function update(Request $request, Cotizacion $cotizacion)
    {
        $data = $request->validate([
            'cliente_id'                => 'nullable|exists:clientes,id',
            'nombre_cliente'            => 'nullable|string|max:255',
            'email_cliente'             => 'nullable|email|max:255',
            'telefono_cliente'          => 'nullable|string|max:40',
            'vendedor_id'               => 'nullable|exists:users,id',
            'fecha'                     => 'nullable|date',
            'fecha_vencimiento'         => 'nullable|date',
            'status'                    => 'nullable|in:borrador,enviada,aceptada,rechazada,vencida',
            'descuento'                 => 'nullable|numeric|min:0|max:100',
            'impuesto_pct'              => 'nullable|numeric|min:0|max:100',
            'notas'                     => 'nullable|string',
            'items'                     => 'nullable|array|min:1',
            'items.*.producto_id'       => 'nullable|exists:productos,id',
            'items.*.descripcion'       => 'required_with:items|string|max:500',
            'items.*.cantidad'          => 'required_with:items|numeric|min:0.01',
            'items.*.precio_unitario'   => 'required_with:items|numeric|min:0',
            'items.*.descuento'         => 'nullable|numeric|min:0|max:100',
        ]);

        $cotizacion->update(array_filter([
            'cliente_id'       => $data['cliente_id'] ?? $cotizacion->cliente_id,
            'nombre_cliente'   => $data['nombre_cliente'] ?? $cotizacion->nombre_cliente,
            'email_cliente'    => $data['email_cliente'] ?? $cotizacion->email_cliente,
            'telefono_cliente' => array_key_exists('telefono_cliente', $data) ? $data['telefono_cliente'] : $cotizacion->telefono_cliente,
            'vendedor_id'      => $data['vendedor_id'] ?? $cotizacion->vendedor_id,
            'fecha'            => $data['fecha'] ?? $cotizacion->fecha,
            'fecha_vencimiento' => array_key_exists('fecha_vencimiento', $data) ? $data['fecha_vencimiento'] : $cotizacion->fecha_vencimiento,
            'status'           => $data['status'] ?? $cotizacion->status,
            'descuento'        => $data['descuento'] ?? $cotizacion->descuento,
            'impuesto_pct'     => $data['impuesto_pct'] ?? $cotizacion->impuesto_pct,
            'notas'            => array_key_exists('notas', $data) ? $data['notas'] : $cotizacion->notas,
        ], fn($v) => $v !== null));

        if (!empty($data['items'])) {
            $cotizacion->items()->delete();
            foreach ($data['items'] as $itemData) {
                $descItem = $itemData['descuento'] ?? 0;
                $subtotal = CotizacionItem::calcularSubtotal(
                    $itemData['cantidad'],
                    $itemData['precio_unitario'],
                    $descItem
                );
                CotizacionItem::create([
                    'cotizacion_id'  => $cotizacion->id,
                    'producto_id'    => $itemData['producto_id'] ?? null,
                    'descripcion'    => $itemData['descripcion'],
                    'cantidad'       => $itemData['cantidad'],
                    'precio_unitario' => $itemData['precio_unitario'],
                    'descuento'      => $descItem,
                    'subtotal'       => $subtotal,
                ]);
            }
            $cotizacion->calcularTotales();
        }

        return response()->json($cotizacion->load(['cliente', 'vendedor:id,name', 'items.producto', 'venta']));
    }

    public function destroy(Cotizacion $cotizacion)
    {
        if (!in_array($cotizacion->status, ['borrador', 'rechazada', 'vencida'])) {
            return response()->json([
                'message' => "No se puede eliminar una cotización en estado '{$cotizacion->status}'.",
            ], 422);
        }

        $cotizacion->delete();
        return response()->json(['message' => 'Cotización eliminada']);
    }

    public function convertir(Cotizacion $cotizacion)
    {
        if ($cotizacion->venta_id) {
            return response()->json(['message' => 'Esta cotización ya fue convertida a venta'], 400);
        }

        if ($cotizacion->status !== 'aceptada') {
            return response()->json(['message' => 'Solo se pueden convertir cotizaciones con status "aceptada"'], 400);
        }

        $cotizacion->loadMissing('items.producto');

        $venta = Venta::create([
            'cliente_id' => $cotizacion->cliente_id,
            'tipo_pago'  => 'credito',
            'estado'     => 'completada',
            'subtotal'   => $cotizacion->subtotal,
            'descuento'  => $cotizacion->descuento,
            'impuesto'   => round($cotizacion->subtotal * ($cotizacion->impuesto_pct / 100), 2),
            'total'      => $cotizacion->total,
            'user_id'    => auth()->id(),
            'notas'      => 'Generada desde cotización ' . $cotizacion->folio,
        ]);

        foreach ($cotizacion->items as $item) {
            VentaItem::create([
                'venta_id'        => $venta->id,
                'producto_id'     => $item->producto_id,
                'nombre_producto' => $item->descripcion,
                'precio_unitario' => $item->precio_unitario,
                'costo_unitario'  => $item->producto?->precio_compra ?? 0,
                'cantidad'        => $item->cantidad,
                'descuento'       => $item->descuento,
                'subtotal'        => $item->subtotal,
            ]);
        }

        $cotizacion->update(['venta_id' => $venta->id, 'status' => 'aceptada']);

        return response()->json([
            'message' => 'Cotización convertida a venta exitosamente',
            'venta_id' => $venta->id,
            'venta_folio' => $venta->folio,
            'cotizacion' => $cotizacion->load(['cliente', 'vendedor:id,name', 'items.producto', 'venta']),
        ]);
    }

    public function convertirAPedido(Request $request, Cotizacion $cotizacion)
    {
        if ($cotizacion->pedido_id) {
            return response()->json(['message' => 'Esta cotización ya fue convertida a pedido'], 400);
        }

        $data = $request->validate([
            'fecha_entrega' => ['nullable', 'date'],
        ]);

        $cotizacion->loadMissing('items.producto');

        $pedido = Pedido::create([
            'cotizacion_id'  => $cotizacion->id,
            'cliente_id'     => $cotizacion->cliente_id,
            'nombre_cliente' => $cotizacion->cliente?->nombre ?? $cotizacion->nombre_cliente,
            'email_cliente'  => $cotizacion->email_cliente ?? $cotizacion->cliente?->email,
            'vendedor_id'    => $cotizacion->vendedor_id ?? auth()->id(),
            'fecha'          => now()->toDateString(),
            'fecha_entrega'  => $data['fecha_entrega'] ?? null,
            'status'         => 'pendiente',
            'descuento'      => $cotizacion->descuento,
            'impuesto_pct'   => $cotizacion->impuesto_pct,
            'subtotal'       => $cotizacion->subtotal,
            'total'          => $cotizacion->total,
            'notas'          => 'Generado desde cotización ' . $cotizacion->folio,
        ]);

        foreach ($cotizacion->items as $item) {
            PedidoItem::create([
                'pedido_id'       => $pedido->id,
                'producto_id'     => $item->producto_id,
                'descripcion'     => $item->descripcion,
                'cantidad'        => $item->cantidad,
                'precio_unitario' => $item->precio_unitario,
                'descuento'       => $item->descuento,
                'subtotal'        => $item->subtotal,
            ]);
        }

        $cotizacion->update([
            'pedido_id' => $pedido->id,
            'status'    => 'aceptada',
        ]);

        return response()->json([
            'message'          => 'Cotización convertida a pedido exitosamente',
            'pedido_id'        => $pedido->id,
            'pedido_folio'     => $pedido->folio,
            'cotizacion'       => $cotizacion->load(['cliente', 'vendedor:id,name', 'items.producto', 'venta']),
        ]);
    }

    public function ticket(Cotizacion $cotizacion)
    {
        $cotizacion->load(['cliente', 'vendedor:id,name', 'items.producto']);
        $html = $this->buildTicketHtml($cotizacion);
        return response($html)->header('Content-Type', 'text/html');
    }

    public function enviar(Cotizacion $cotizacion)
    {
        $cotizacion->load(['cliente', 'vendedor:id,name', 'items.producto']);

        $email = $cotizacion->email_cliente ?? $cotizacion->cliente?->email ?? null;

        if (!$email) {
            return response()->json(['message' => 'No hay email configurado para esta cotización'], 422);
        }

        try {
            $html = $this->buildEmailWrapper(
                $this->buildCotizacionBodyHtml($cotizacion),
                'Cotización'
            );
            $this->enviarConCC($email, 'Cotización ' . $cotizacion->folio, $html);

            if ($cotizacion->status === 'borrador') {
                $cotizacion->update(['status' => 'enviada']);
            }

            return response()->json([
                'message' => 'Cotización enviada a ' . $email,
                'status'  => $cotizacion->status,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error enviando cotización: ' . $e->getMessage());
            return response()->json(['message' => 'Error al enviar email: ' . $e->getMessage()], 500);
        }
    }

    private function buildCotizacionBodyHtml(Cotizacion $cotizacion): string
    {
        $color = $this->getNotifConfig()['color_primario'] ?? '#2563eb';
        $nombreCliente = htmlspecialchars($cotizacion->cliente?->nombre ?? $cotizacion->nombre_cliente ?? 'Cliente general');
        $fecha = $cotizacion->fecha->format('d/m/Y');
        $vencimiento = $cotizacion->fecha_vencimiento?->format('d/m/Y') ?? 'Sin vencimiento';

        $itemsHtml = '';
        foreach ($cotizacion->items as $item) {
            $itemsHtml .= sprintf(
                '<tr><td style="padding:8px;border-bottom:1px solid #f3f4f6">%s</td>
                 <td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:center">%s</td>
                 <td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:right">$%s</td>
                 <td style="padding:8px;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:600">$%s</td></tr>',
                htmlspecialchars($item->descripcion),
                number_format($item->cantidad, 2),
                number_format($item->precio_unitario, 2),
                number_format($item->subtotal, 2)
            );
        }

        $totalesHtml = '';
        if ($cotizacion->descuento > 0) {
            $totalesHtml .= '<tr><td style="text-align:right;padding:4px 8px;color:#6b7280">Descuento (' . $cotizacion->descuento . '%):</td>
                <td style="text-align:right;padding:4px 8px;color:#d97706">-$' . number_format($cotizacion->subtotal * $cotizacion->descuento / 100, 2) . '</td></tr>';
        }
        if ($cotizacion->impuesto_pct > 0) {
            $totalesHtml .= '<tr><td style="text-align:right;padding:4px 8px;color:#6b7280">IVA (' . $cotizacion->impuesto_pct . '%):</td>
                <td style="text-align:right;padding:4px 8px">$' . number_format($cotizacion->subtotal * $cotizacion->impuesto_pct / 100, 2) . '</td></tr>';
        }

        $notasHtml = $cotizacion->notas
            ? '<div style="margin-top:20px;padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
                <p style="margin:0 0 4px;font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em">Notas</p>
                <p style="margin:0;font-size:13px;color:#374151">' . htmlspecialchars($cotizacion->notas) . '</p></div>'
            : '';

        $total = number_format($cotizacion->total, 2);

        return '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">Cliente</p>
    <p style="margin:0;font-weight:600;color:#111827">' . $nombreCliente . '</p>
  </div>
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">Folio</p>
    <p style="margin:0;font-weight:600;color:' . $color . '">' . $cotizacion->folio . '</p>
  </div>
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">Fecha</p>
    <p style="margin:0;font-weight:600;color:#111827">' . $fecha . '</p>
  </div>
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">Vence</p>
    <p style="margin:0;font-weight:600;color:#111827">' . $vencimiento . '</p>
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
<table width="300" cellpadding="0" cellspacing="0" style="margin-left:auto">
  ' . $totalesHtml . '
  <tr>
    <td style="text-align:right;padding:8px;font-size:16px;font-weight:700;color:' . $color . ';border-top:2px solid #e5e7eb">TOTAL:</td>
    <td style="text-align:right;padding:8px;font-size:16px;font-weight:700;color:' . $color . ';border-top:2px solid #e5e7eb">$' . $total . '</td>
  </tr>
</table>' . $notasHtml;
    }

    private function buildTicketHtml(Cotizacion $cotizacion): string
    {
        $tenantId = app()->bound('tenant_id') ? app('tenant_id') : 'global';
        $config = \Illuminate\Support\Facades\Cache::get("ventas_configuracion_{$tenantId}", []);
        $empresa = $config['empresa'] ?? [];

        $fecha = $cotizacion->fecha->format('d/m/Y');
        $vencimiento = $cotizacion->fecha_vencimiento?->format('d/m/Y') ?? 'Sin vencimiento';
        $nombreCliente = $cotizacion->cliente?->nombre ?? $cotizacion->nombre_cliente ?? 'Cliente general';

        $itemsHtml = '';
        foreach ($cotizacion->items as $item) {
            $itemsHtml .= sprintf(
                '<tr><td>%s</td><td style="text-align:center">%s</td><td style="text-align:right">$%s</td><td style="text-align:right">$%s</td></tr>',
                htmlspecialchars($item->descripcion),
                number_format($item->cantidad, 2),
                number_format($item->precio_unitario, 2),
                number_format($item->subtotal, 2)
            );
        }

        $statusLabels = [
            'borrador' => 'Borrador', 'enviada' => 'Enviada', 'aceptada' => 'Aceptada',
            'rechazada' => 'Rechazada', 'vencida' => 'Vencida',
        ];

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Cotización ' . $cotizacion->folio . '</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 13px; margin: 30px; color: #111; }
  h1 { font-size: 20px; margin: 0; }
  .header { display: flex; justify-content: space-between; margin-bottom: 20px; }
  .empresa { font-size: 12px; color: #555; }
  .folio { text-align: right; }
  .folio h1 { color: #2563eb; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; background: #eff6ff; color: #2563eb; }
  table { width: 100%; border-collapse: collapse; margin-top: 20px; }
  th { background: #f3f4f6; padding: 8px; text-align: left; font-size: 11px; text-transform: uppercase; }
  td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; }
  .totales td { border: none; padding: 3px 8px; }
  .total-final { font-size: 16px; font-weight: bold; color: #2563eb; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 16px 0; }
  .info-box { background: #f9fafb; padding: 10px; border-radius: 6px; }
  .info-box label { font-size: 10px; color: #6b7280; text-transform: uppercase; display: block; }
  .info-box span { font-weight: 600; }
  @media print { body { margin: 10px; } button { display: none; } }
</style></head><body>
<div class="header">
  <div class="empresa">
    <strong>' . htmlspecialchars($empresa['nombre'] ?? 'Mi Empresa') . '</strong><br>
    ' . htmlspecialchars($empresa['direccion'] ?? '') . '<br>
    ' . htmlspecialchars($empresa['telefono'] ?? '') . '
  </div>
  <div class="folio">
    <h1>' . $cotizacion->folio . '</h1>
    <span class="badge">' . ($statusLabels[$cotizacion->status] ?? $cotizacion->status) . '</span>
  </div>
</div>
<div class="info-grid">
  <div class="info-box"><label>Cliente</label><span>' . htmlspecialchars($nombreCliente) . '</span></div>
  <div class="info-box"><label>Vendedor</label><span>' . htmlspecialchars($cotizacion->vendedor?->name ?? '—') . '</span></div>
  <div class="info-box"><label>Fecha</label><span>' . $fecha . '</span></div>
  <div class="info-box"><label>Vence</label><span>' . $vencimiento . '</span></div>
</div>
<table>
  <thead><tr><th>Descripción</th><th style="text-align:center">Cant.</th><th style="text-align:right">Precio Unit.</th><th style="text-align:right">Subtotal</th></tr></thead>
  <tbody>' . $itemsHtml . '</tbody>
</table>
<table style="width:300px;margin-left:auto;margin-top:10px">
  <tr><td>Subtotal:</td><td style="text-align:right">$' . number_format($cotizacion->subtotal, 2) . '</td></tr>';

        if ($cotizacion->descuento > 0) {
            $html .= '<tr><td>Descuento (' . $cotizacion->descuento . '%):</td><td style="text-align:right">-$' . number_format($cotizacion->subtotal * $cotizacion->descuento / 100, 2) . '</td></tr>';
        }
        if ($cotizacion->impuesto_pct > 0) {
            $html .= '<tr><td>IVA (' . $cotizacion->impuesto_pct . '%):</td><td style="text-align:right">$' . number_format($cotizacion->subtotal * $cotizacion->impuesto_pct / 100, 2) . '</td></tr>';
        }

        $html .= '<tr class="total-final"><td><strong>TOTAL:</strong></td><td style="text-align:right"><strong>$' . number_format($cotizacion->total, 2) . '</strong></td></tr>
</table>';

        if ($cotizacion->notas) {
            $html .= '<p style="margin-top:20px;font-size:12px;color:#555"><strong>Notas:</strong> ' . htmlspecialchars($cotizacion->notas) . '</p>';
        }

        $html .= '<br><button onclick="window.print()">Imprimir</button></body></html>';

        return $html;
    }

    public function enviarWhatsApp(Request $request, Cotizacion $cotizacion)
    {
        $data = $request->validate([
            'cliente_id' => ['nullable', 'integer'],
            'telefono'   => ['nullable', 'string', 'max:30'],
        ]);

        $cotizacion->load(['items']);

        $telefono = null;
        if (!empty($data['cliente_id'])) {
            $cliente = Cliente::withoutGlobalScopes()->find($data['cliente_id']);
            $telefono = $cliente?->telefono;
            if (!$cotizacion->cliente_id) {
                $cotizacion->update(['cliente_id' => $data['cliente_id']]);
            }
        } elseif (!empty($data['telefono'])) {
            $telefono = $data['telefono'];
            // Always persist the provided phone so future resends use the corrected number
            $cotizacion->update(['telefono_cliente' => $telefono]);
        }

        if (!$telefono) {
            return response()->json(['message' => 'No se encontró número de teléfono.'], 422);
        }

        $svc = app(WhatsAppService::class);
        $sucursalId = $cotizacion->sucursal_id ? (int) $cotizacion->sucursal_id : null;
        $publicConfig = $svc->resolvePublicConfig((int) $cotizacion->empresa_id, $sucursalId);
        $technicalConfig = $svc->resolveTechnicalConfig((int) $cotizacion->empresa_id, $sucursalId);

        if (!$svc->isConnected($technicalConfig)) {
            return response()->json(['message' => 'WhatsApp no está conectado.'], 422);
        }

        $businessName = $svc->resolveBusinessName((int) $cotizacion->empresa_id, $sucursalId, $technicalConfig, $publicConfig);

        $itemLines = $cotizacion->items->take(5)->map(function ($item) {
            return '▸ ' . ($item->descripcion ?? $item->nombre_producto ?? '—') . ' × ' . (int) $item->cantidad
                . ' — $' . number_format((float) ($item->precio_unitario * $item->cantidad), 2);
        })->implode("\n");

        if ($cotizacion->items->count() > 5) {
            $itemLines .= "\n▸ _y " . ($cotizacion->items->count() - 5) . ' producto(s) más_';
        }

        $body = implode("\n", [
            "📋 *Cotización de {$businessName}*",
            '',
            "🔖 *Folio:* {$cotizacion->folio}",
            '💰 *Total:* $' . number_format((float) $cotizacion->total, 2),
            '',
            '*Productos:*',
            $itemLines,
        ]);

        $ticketUrl = $cotizacion->ticket_token
            ? url('/t/cotizacion/' . $cotizacion->ticket_token)
            : null;

        try {
            if ($ticketUrl) {
                $svc->sendTicketMessage($technicalConfig, $telefono, $body, $ticketUrl, 'Ver cotización');
            } else {
                $svc->sendTextMessage($technicalConfig, $telefono, $body);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($cotizacion->status === 'borrador') {
            $cotizacion->update(['status' => 'enviada']);
        }

        return response()->json(['message' => 'Cotización enviada por WhatsApp.', 'status' => $cotizacion->status]);
    }
}
