<?php

namespace App\Http\Controllers\Api;

use App\Models\Cliente;
use App\Models\Caja;
use App\Models\Producto;
use App\Models\Venta;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Events\VentaCompletada;
use Illuminate\Validation\Rule;

class VentaController extends Controller
{
    public function index(Request $request)
    {
        $query = Venta::with(['user', 'cliente', 'canceladoPor'])
            ->withCount('items')
            ->latest();

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($q) use ($search) {
                $q->where('folio', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('created_at', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            $query->whereDate('created_at', '<=', $request->fecha_fin);
        }

        if ($request->filled('tipo_pago')) {
            $query->where('tipo_pago', $request->tipo_pago);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'tipo_pago' => ['required', Rule::in(['efectivo', 'tarjeta', 'credito', 'mixto'])],
            'cliente_id' => ['nullable', 'exists:clientes,id'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'descuento' => ['nullable', 'numeric', 'min:0'],
            'impuesto' => ['nullable', 'numeric', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'exists:productos,id'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'items.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'items.*.descuento' => ['nullable', 'numeric', 'min:0'],
            'pagos' => ['nullable', 'array'],
            'pagos.*.metodo' => ['required_with:pagos', Rule::in(['efectivo', 'tarjeta', 'credito'])],
            'pagos.*.monto' => ['required_with:pagos', 'numeric', 'min:0'],
            'pagos.*.cambio' => ['nullable', 'numeric', 'min:0'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['tipo_pago'] === 'credito' && empty($data['cliente_id'])) {
            return response()->json(['message' => 'Selecciona un cliente para ventas a crédito'], 422);
        }

        $caja = Caja::where('user_id', $request->user()->id)
            ->where('estado', 'abierta')
            ->latest('abierta_at')
            ->first();

        if (! $caja) {
            return response()->json(['message' => 'Debes abrir caja antes de vender'], 422);
        }

        $venta = DB::transaction(function () use ($request, $data, $caja) {
            $items = collect($data['items'])->map(function ($item) {
                $producto = Producto::lockForUpdate()->findOrFail($item['producto_id']);
                $cantidad = (int) $item['cantidad'];

                if ($producto->control_stock && $producto->stock < $cantidad) {
                    abort(422, "Stock insuficiente para {$producto->nombre}");
                }

                $precio = (float) ($item['precio_unitario'] ?? $producto->precio);
                $descuento = (float) ($item['descuento'] ?? 0);
                $subtotal = max(0, ($precio * $cantidad) - $descuento);

                return compact('producto', 'cantidad', 'precio', 'descuento', 'subtotal');
            });

            $subtotal = $items->sum('subtotal');
            $descuento = (float) ($data['descuento'] ?? 0);
            $impuesto = (float) ($data['impuesto'] ?? 0);
            $total = (float) ($data['total'] ?? max(0, $subtotal - $descuento + $impuesto));

            if ($data['tipo_pago'] === 'credito' && $data['cliente_id']) {
                $cliente = Cliente::lockForUpdate()->findOrFail($data['cliente_id']);
                $disponible = (float) $cliente->limite_credito - (float) $cliente->saldo_credito;
                if ($total > $disponible) {
                    abort(422, "Crédito insuficiente. Disponible: $" . number_format($disponible, 2) . ", Requerido: $" . number_format($total, 2));
                }
            }

            $venta = Venta::create([
                'user_id' => $request->user()->id,
                'caja_id' => $caja->id,
                'cliente_id' => $data['cliente_id'] ?? null,
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'impuesto' => $impuesto,
                'total' => $total,
                'tipo_pago' => $data['tipo_pago'],
                'estado' => 'completada',
                'notas' => $data['notas'] ?? null,
            ]);

            foreach ($items as $item) {
                $venta->items()->create([
                    'producto_id' => $item['producto']->id,
                    'nombre_producto' => $item['producto']->nombre,
                    'precio_unitario' => $item['precio'],
                    'costo_unitario' => $item['producto']->costo,
                    'cantidad' => $item['cantidad'],
                    'descuento' => $item['descuento'],
                    'subtotal' => $item['subtotal'],
                ]);

                if ($item['producto']->control_stock) {
                    $item['producto']->decrement('stock', $item['cantidad']);
                }
            }

            $pagos = $data['pagos'] ?? [[
                'metodo' => $data['tipo_pago'] === 'mixto' ? 'efectivo' : $data['tipo_pago'],
                'monto' => $total,
                'cambio' => 0,
            ]];

            foreach ($pagos as $pago) {
                $venta->pagos()->create([
                    'metodo' => $pago['metodo'],
                    'monto' => $pago['monto'],
                    'cambio' => $pago['cambio'] ?? 0,
                    'referencia' => $pago['referencia'] ?? null,
                ]);
            }

            if ($data['tipo_pago'] === 'credito' && $data['cliente_id']) {
                Cliente::where('id', $data['cliente_id'])->increment('saldo_credito', $total);
            }

            $this->actualizarCaja($caja, $data['tipo_pago'], $total);

            return $venta->load(['user', 'cliente', 'items.producto', 'pagos']);
        });

        VentaCompletada::dispatch($venta);

        return response()->json($venta, 201);
    }

    public function show(Venta $venta)
    {
        return response()->json($venta->load(['user', 'cliente', 'items.producto', 'pagos', 'abonos', 'canceladoPor']));
    }

    public function cancelar(Venta $venta, Request $request)
    {
        if ($venta->estado === 'cancelada') {
            return response()->json(['message' => 'La venta ya está cancelada'], 422);
        }

        $venta = DB::transaction(function () use ($venta, $request) {
            $venta->load(['items.producto', 'caja']);

            foreach ($venta->items as $item) {
                if ($item->producto?->control_stock) {
                    $item->producto->increment('stock', $item->cantidad);
                }
            }

            if ($venta->caja) {
                $this->actualizarCaja($venta->caja, $venta->tipo_pago, -1 * (float) $venta->total);
            }

            $venta->update(['estado' => 'cancelada', 'cancelada_por' => $request->user()->id]);

            return $venta->fresh(['user', 'canceladoPor', 'items.producto', 'pagos']);
        });

        return response()->json($venta);
    }

    public function ticket(Venta $venta)
    {
        return response()->json($venta->load(['user', 'cliente', 'items.producto', 'pagos', 'canceladoPor']));
    }

    private function actualizarCaja(Caja $caja, string $tipoPago, float $total): void
    {
        $campo = match ($tipoPago) {
            'tarjeta' => 'total_tarjeta',
            'credito' => 'total_credito',
            default => 'total_efectivo',
        };

        $caja->increment($campo, $total);
        $caja->increment('total_ventas', $total);
        $caja->increment('num_transacciones', $total >= 0 ? 1 : -1);
    }
}
