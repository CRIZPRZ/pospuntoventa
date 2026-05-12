<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Models\PedidoItem;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
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

    public function destroy(Pedido $pedido)
    {
        if (in_array($pedido->status, ['enviado', 'entregado'])) {
            return response()->json(['message' => "No se puede eliminar un pedido en estado '{$pedido->status}'"], 422);
        }

        $pedido->delete();

        return response()->json(['message' => 'Pedido eliminado']);
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
