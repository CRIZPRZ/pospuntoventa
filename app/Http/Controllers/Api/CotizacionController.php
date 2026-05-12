<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Venta;
use App\Models\VentaItem;
use Illuminate\Http\Request;

class CotizacionController extends Controller
{
    public function index(Request $request)
    {
        $query = Cotizacion::with(['cliente:id,nombre', 'vendedor:id,name', 'items'])
            ->latest();

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
            'vendedor_id'      => $data['vendedor_id'] ?? auth()->id(),
            'fecha'            => $data['fecha'],
            'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
            'status'           => $data['status'] ?? 'borrador',
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

        $email = $cotizacion->email_cliente
            ?? $cotizacion->cliente?->email
            ?? null;

        if (!$email) {
            return response()->json(['message' => 'No hay email configurado para esta cotización'], 422);
        }

        $html = $this->buildTicketHtml($cotizacion);

        try {
            \Illuminate\Support\Facades\Mail::html($html, function ($message) use ($cotizacion, $email) {
                $message->to($email)->subject('Cotización ' . $cotizacion->folio);
            });

            if ($cotizacion->status === 'borrador') {
                $cotizacion->update(['status' => 'enviada']);
            }

            return response()->json([
                'message' => 'Cotización enviada a ' . $email,
                'status' => $cotizacion->status,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error enviando cotización: ' . $e->getMessage());
            return response()->json(['message' => 'Error al enviar email: ' . $e->getMessage()], 500);
        }
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
}
