<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Traits\EnviaCorreosTrait;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
    use EnviaCorreosTrait;
    public function index(Request $request)
    {
        $query = Pedido::with(['cliente:id,nombre', 'vendedor:id,name', 'cotizacion:id,folio'])
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

    public function show(Pedido $pedido)
    {
        return response()->json($pedido->load(['cliente', 'vendedor:id,name', 'items.producto', 'cotizacion:id,folio']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id'                => 'nullable|exists:clientes,id',
            'nombre_cliente'            => 'nullable|string|max:255',
            'email_cliente'             => 'nullable|email|max:255',
            'vendedor_id'               => 'nullable|exists:users,id',
            'fecha'                     => 'required|date',
            'fecha_entrega'             => 'nullable|date',
            'status'                    => 'nullable|in:pendiente,confirmado,en_proceso,enviado,entregado,cancelado',
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

        $pedido = Pedido::create([
            'cliente_id'     => $data['cliente_id'] ?? null,
            'nombre_cliente' => $data['nombre_cliente'] ?? null,
            'email_cliente'  => $data['email_cliente'] ?? null,
            'vendedor_id'    => $data['vendedor_id'] ?? auth()->id(),
            'fecha'          => $data['fecha'],
            'fecha_entrega'  => $data['fecha_entrega'] ?? null,
            'status'         => $data['status'] ?? 'pendiente',
            'descuento'      => $data['descuento'] ?? 0,
            'impuesto_pct'   => $data['impuesto_pct'] ?? 0,
            'notas'          => $data['notas'] ?? null,
        ]);

        $this->createItems($pedido, $data['items']);
        $this->calcularTotales($pedido);

        $email = $pedido->email_cliente ?? $pedido->cliente?->email ?? null;
        if ($this->notifAlCrear() && $email) {
            try {
                $pedido->loadMissing(['cliente', 'vendedor:id,name', 'items']);
                $html = $this->buildEmailWrapper(
                    $this->buildPedidoBodyHtml($pedido),
                    'Pedido'
                );
                $this->enviarConCC($email, 'Pedido ' . $pedido->folio, $html);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Auto-envío pedido falló: ' . $e->getMessage());
            }
        }

        return response()->json($pedido->load(['cliente', 'vendedor:id,name', 'items.producto']), 201);
    }

    public function update(Request $request, Pedido $pedido)
    {
        if ($pedido->status === 'entregado') {
            return response()->json(['message' => 'No se puede editar un pedido ya entregado'], 422);
        }

        $data = $request->validate([
            'cliente_id'                => 'nullable|exists:clientes,id',
            'nombre_cliente'            => 'nullable|string|max:255',
            'email_cliente'             => 'nullable|email|max:255',
            'vendedor_id'               => 'nullable|exists:users,id',
            'fecha'                     => 'nullable|date',
            'fecha_entrega'             => 'nullable|date',
            'status'                    => 'nullable|in:pendiente,confirmado,en_proceso,enviado,entregado,cancelado',
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

        $pedido->update(array_filter([
            'cliente_id'     => $data['cliente_id'] ?? $pedido->cliente_id,
            'nombre_cliente' => $data['nombre_cliente'] ?? $pedido->nombre_cliente,
            'email_cliente'  => $data['email_cliente'] ?? $pedido->email_cliente,
            'vendedor_id'    => $data['vendedor_id'] ?? $pedido->vendedor_id,
            'fecha'          => $data['fecha'] ?? $pedido->fecha,
            'fecha_entrega'  => array_key_exists('fecha_entrega', $data) ? $data['fecha_entrega'] : $pedido->fecha_entrega,
            'status'         => $data['status'] ?? $pedido->status,
            'descuento'      => $data['descuento'] ?? $pedido->descuento,
            'impuesto_pct'   => $data['impuesto_pct'] ?? $pedido->impuesto_pct,
            'notas'          => array_key_exists('notas', $data) ? $data['notas'] : $pedido->notas,
        ], fn ($v) => $v !== null));

        if (!empty($data['items'])) {
            $pedido->items()->delete();
            $this->createItems($pedido, $data['items']);
            $this->calcularTotales($pedido);
        }

        return response()->json($pedido->load(['cliente', 'vendedor:id,name', 'items.producto', 'cotizacion:id,folio']));
    }

    public function cambiarStatus(Request $request, Pedido $pedido)
    {
        $data = $request->validate([
            'status' => 'required|in:pendiente,confirmado,en_proceso,enviado,entregado,cancelado',
        ]);

        $pedido->update(['status' => $data['status']]);

        return response()->json([
            'message' => 'Estado actualizado',
            'status'  => $pedido->status,
        ]);
    }

    public function enviar(Pedido $pedido)
    {
        $pedido->load(['cliente', 'vendedor:id,name', 'items.producto']);
        $email = $pedido->email_cliente ?? $pedido->cliente?->email ?? null;

        if (!$email) {
            return response()->json(['message' => 'No hay email configurado para este pedido'], 422);
        }

        try {
            $html = $this->buildEmailWrapper($this->buildPedidoBodyHtml($pedido), 'Pedido');
            $this->enviarConCC($email, 'Pedido ' . $pedido->folio, $html);
            return response()->json(['message' => 'Correo enviado a ' . $email]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error enviando pedido: ' . $e->getMessage());
            return response()->json(['message' => 'Error al enviar: ' . $e->getMessage()], 500);
        }
    }

    public function recordar(Pedido $pedido)
    {
        $pedido->load(['cliente', 'vendedor:id,name', 'items.producto']);
        $email = $pedido->email_cliente ?? $pedido->cliente?->email ?? null;

        if (!$email) {
            return response()->json(['message' => 'No hay email configurado para este pedido'], 422);
        }

        try {
            $config  = $this->getConfig();
            $empresa = $config['empresa'] ?? [];
            $tel     = $empresa['telefono'] ?? '';
            $dir     = $empresa['direccion'] ?? '';

            $recordatorioHtml = '<div style="padding:16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;margin-bottom:20px">
                <p style="margin:0 0 4px;font-weight:700;color:#d97706">🔔 Tu pedido está listo para recolección</p>
                <p style="margin:0;color:#92400e;font-size:13px">Puedes pasar a recogerlo en cualquier momento.</p>'
                . ($dir ? '<p style="margin:8px 0 0;color:#92400e;font-size:13px">📍 ' . htmlspecialchars($dir) . '</p>' : '')
                . ($tel ? '<p style="margin:4px 0 0;color:#92400e;font-size:13px">📞 ' . htmlspecialchars($tel) . '</p>' : '')
                . '</div>';

            $html = $this->buildEmailWrapper(
                $recordatorioHtml . $this->buildPedidoBodyHtml($pedido),
                'Recordatorio de recolección'
            );
            $this->enviarConCC($email, 'Recordatorio: Pedido ' . $pedido->folio . ' listo para recolección', $html);
            return response()->json(['message' => 'Recordatorio enviado a ' . $email]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error enviando recordatorio: ' . $e->getMessage());
            return response()->json(['message' => 'Error al enviar: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Pedido $pedido)
    {
        if (in_array($pedido->status, ['enviado', 'entregado'])) {
            return response()->json(['message' => "No se puede eliminar un pedido en estado '{$pedido->status}'"], 422);
        }

        $pedido->delete();

        return response()->json(['message' => 'Pedido eliminado']);
    }

    private function buildPedidoBodyHtml(Pedido $pedido): string
    {
        $color         = $this->getNotifConfig()['color_primario'] ?? '#7c3aed';
        $nombreCliente = htmlspecialchars($pedido->cliente?->nombre ?? $pedido->nombre_cliente ?? 'Cliente general');
        $fecha         = $pedido->fecha instanceof \Carbon\Carbon
            ? $pedido->fecha->format('d/m/Y')
            : (is_string($pedido->fecha) ? substr($pedido->fecha, 0, 10) : '—');
        $entrega = $pedido->fecha_entrega
            ? (is_string($pedido->fecha_entrega) ? substr($pedido->fecha_entrega, 0, 10) : $pedido->fecha_entrega->format('d/m/Y'))
            : 'Sin fecha de entrega';

        $statusLabels = [
            'pendiente'  => 'Pendiente',  'confirmado' => 'Confirmado',
            'en_proceso' => 'En proceso', 'enviado'    => 'Enviado',
            'entregado'  => 'Entregado',  'cancelado'  => 'Cancelado',
        ];

        $itemsHtml = '';
        foreach ($pedido->items as $item) {
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
        if ($pedido->descuento > 0) {
            $totalesHtml .= '<tr><td style="text-align:right;padding:4px 8px;color:#6b7280">Descuento (' . $pedido->descuento . '%):</td>
                <td style="text-align:right;padding:4px 8px;color:#d97706">-$' . number_format($pedido->subtotal * $pedido->descuento / 100, 2) . '</td></tr>';
        }
        if ($pedido->impuesto_pct > 0) {
            $totalesHtml .= '<tr><td style="text-align:right;padding:4px 8px;color:#6b7280">IVA (' . $pedido->impuesto_pct . '%):</td>
                <td style="text-align:right;padding:4px 8px">$' . number_format($pedido->subtotal * $pedido->impuesto_pct / 100, 2) . '</td></tr>';
        }

        $notasHtml = $pedido->notas
            ? '<div style="margin-top:20px;padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
                <p style="margin:0 0 4px;font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em">Notas</p>
                <p style="margin:0;font-size:13px;color:#374151">' . htmlspecialchars($pedido->notas) . '</p></div>'
            : '';

        return '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">Cliente</p>
    <p style="margin:0;font-weight:600;color:#111827">' . $nombreCliente . '</p>
  </div>
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">Folio / Estado</p>
    <p style="margin:0;font-weight:600;color:' . $color . '">' . $pedido->folio . '</p>
    <p style="margin:2px 0 0;font-size:11px;color:#6b7280">' . ($statusLabels[$pedido->status] ?? $pedido->status) . '</p>
  </div>
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">Fecha</p>
    <p style="margin:0;font-weight:600;color:#111827">' . $fecha . '</p>
  </div>
  <div style="padding:12px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">
    <p style="margin:0 0 2px;font-size:11px;color:#9ca3af;text-transform:uppercase">Entrega estimada</p>
    <p style="margin:0;font-weight:600;color:#111827">' . $entrega . '</p>
  </div>
</div>

<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:12px">
  <thead>
    <tr style="background:#f3f4f6">
      <th style="padding:10px 8px;text-align:left;font-size:11px;color:#6b7280;text-transform:uppercase">Descripción</th>
      <th style="padding:10px 8px;text-align:center;font-size:11px;color:#6b7280;text-transform:uppercase">Cant.</th>
      <th style="padding:10px 8px;text-align:right;font-size:11px;color:#6b7280;text-transform:uppercase">P.U.</th>
      <th style="padding:10px 8px;text-align:right;font-size:11px;color:#6b7280;text-transform:uppercase">Subtotal</th>
    </tr>
  </thead>
  <tbody>' . $itemsHtml . '</tbody>
</table>

<table width="300" cellpadding="0" cellspacing="0" style="margin-left:auto">
  ' . $totalesHtml . '
  <tr>
    <td style="text-align:right;padding:8px;font-size:16px;font-weight:700;color:' . $color . ';border-top:2px solid #e5e7eb">TOTAL:</td>
    <td style="text-align:right;padding:8px;font-size:16px;font-weight:700;color:' . $color . ';border-top:2px solid #e5e7eb">$' . number_format($pedido->total, 2) . '</td>
  </tr>
</table>' . $notasHtml;
    }

    private function createItems(Pedido $pedido, array $items): void
    {
        foreach ($items as $itemData) {
            $desc = $itemData['descuento'] ?? 0;
            PedidoItem::create([
                'pedido_id'       => $pedido->id,
                'producto_id'     => $itemData['producto_id'] ?? null,
                'descripcion'     => $itemData['descripcion'],
                'cantidad'        => $itemData['cantidad'],
                'precio_unitario' => $itemData['precio_unitario'],
                'descuento'       => $desc,
                'subtotal'        => PedidoItem::calcularSubtotal($itemData['cantidad'], $itemData['precio_unitario'], $desc),
            ]);
        }
    }

    private function calcularTotales(Pedido $pedido): void
    {
        $pedido->loadMissing('items');
        $subtotalBruto  = $pedido->items->sum('subtotal');
        $descuentoMonto = $subtotalBruto * ($pedido->descuento / 100);
        $base           = $subtotalBruto - $descuentoMonto;
        $impuestoMonto  = $base * ($pedido->impuesto_pct / 100);
        $pedido->subtotal = round($base, 2);
        $pedido->total    = round($base + $impuestoMonto, 2);
        $pedido->save();
    }
}
