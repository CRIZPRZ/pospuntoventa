<?php

namespace App\Http\Controllers\Api;

use App\Models\Venta;
use App\Models\VentaItem;
use App\Models\Producto;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Abono;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportesController extends Controller
{
    private function sucursalId(Request $request): ?int
    {
        if ($request->filled('sucursal_id')) {
            return (int) $request->sucursal_id;
        }
        return app()->bound('sucursal_id') ? (int) app('sucursal_id') : null;
    }

    public function general(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin'    => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        $inicio     = $request->filled('fecha_inicio') ? Carbon::parse($request->fecha_inicio)->startOfDay() : Carbon::now()->startOfMonth();
        $fin        = $request->filled('fecha_fin')    ? Carbon::parse($request->fecha_fin)->endOfDay()      : Carbon::now()->endOfDay();
        $sucursalId = $this->sucursalId($request);

        $ventasQ = Venta::whereBetween('created_at', [$inicio, $fin])
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId));

        $ventas           = (clone $ventasQ)->where('estado', '!=', 'cancelada')->get();
        $ventasCanceladas = (clone $ventasQ)->where('estado', 'cancelada')->get();

        $totalVentas   = $ventas->count();
        $totalIngresos = $ventas->sum('total');
        $totalCancelado = $ventasCanceladas->sum('total');
        $numCanceladas  = $ventasCanceladas->count();

        $totalCosto = VentaItem::whereIn('venta_id', $ventas->pluck('id'))
            ->get()
            ->sum(fn ($item) => ($item->costo_unitario ?? 0) * $item->cantidad);

        $gananciaNeta  = (float) $totalIngresos - $totalCosto;
        $margen        = $totalIngresos > 0 ? ($gananciaNeta / $totalIngresos) * 100 : 0;
        $ticketPromedio = $totalVentas > 0 ? $totalIngresos / $totalVentas : 0;

        $ventasEfectivo = $ventas->where('tipo_pago', 'efectivo')->sum('total');
        $ventasTarjeta  = $ventas->where('tipo_pago', 'tarjeta')->sum('total');
        $ventasCredito  = $ventas->where('tipo_pago', 'credito')->sum('total');

        $productos = Producto::where('activo', true)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->get();

        $valorInventario      = $productos->sum(fn ($p) => ($p->costo ?? 0) * ($p->stock ?? 0));
        $valorInventarioVenta = $productos->sum(fn ($p) => ($p->precio ?? 0) * ($p->stock ?? 0));
        $totalProductos       = $productos->sum('stock');
        $productosBajoStock   = $productos->filter(fn ($p) => $p->control_stock && $p->stock <= $p->stock_minimo)->count();
        $productosSinStock    = $productos->filter(fn ($p) => $p->control_stock && $p->stock <= 0)->count();

        $clientes = Cliente::where('activo', true)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->get();

        $totalCredito      = $clientes->sum('saldo_credito');
        $clientesConDeuda  = $clientes->filter(fn ($c) => (float) $c->saldo_credito > 0)->count();

        $totalAbonos = Abono::whereBetween('created_at', [$inicio, $fin])
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->sum('monto');

        $cajasCerradas = Caja::whereBetween('abierta_at', [$inicio, $fin])
            ->where('estado', 'cerrada')
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->get();

        $cajas = $cajasCerradas->map(fn ($caja) => [
            'id'               => $caja->id,
            'usuario'          => $caja->user?->name,
            'abierta'          => $caja->abierta_at?->format('Y-m-d H:i'),
            'cerrada'          => $caja->cerrada_at?->format('Y-m-d H:i'),
            'cerrada_por'      => $caja->cerradaPor?->name,
            'fondo_inicial'    => (float) $caja->fondo_inicial,
            'efectivo_contado' => (float) $caja->efectivo_contado,
            'diferencia'       => (float) $caja->diferencia,
        ])->values()->all();

        return response()->json([
            'periodo' => [
                'inicio' => $inicio->format('Y-m-d'),
                'fin'    => $fin->format('Y-m-d'),
            ],
            'ventas' => [
                'total'           => $totalVentas,
                'ingresos'        => (float) $totalIngresos,
                'ticket_promedio' => (float) $ticketPromedio,
                'canceladas'      => $numCanceladas,
                'monto_cancelado' => (float) $totalCancelado,
            ],
            'ganancias' => [
                'ingresos'           => (float) $totalIngresos,
                'costo_ventas'       => (float) $totalCosto,
                'ganancia_neta'      => (float) $gananciaNeta,
                'margen_porcentaje'  => round($margen, 1),
            ],
            'por_pago' => [
                'efectivo' => (float) $ventasEfectivo,
                'tarjeta'  => (float) $ventasTarjeta,
                'credito'  => (float) $ventasCredito,
            ],
            'inventario' => [
                'valor_costo'        => (float) $valorInventario,
                'valor_venta'        => (float) $valorInventarioVenta,
                'ganancia_potencial' => (float) $valorInventarioVenta - $valorInventario,
                'unidades'           => (int) $totalProductos,
                'bajo_stock'         => $productosBajoStock,
                'sin_stock'          => $productosSinStock,
            ],
            'creditos' => [
                'clientes_con_deuda' => $clientesConDeuda,
                'total_deuda'        => (float) $totalCredito,
                'abonos_periodo'     => (float) $totalAbonos,
            ],
            'cajas' => $cajas,
        ]);
    }

    public function ventasPorDia(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin'    => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        $inicio     = $request->filled('fecha_inicio') ? Carbon::parse($request->fecha_inicio)->startOfDay() : Carbon::now()->subDays(29)->startOfDay();
        $fin        = $request->filled('fecha_fin')    ? Carbon::parse($request->fecha_fin)->endOfDay()      : Carbon::now()->endOfDay();
        $sucursalId = $this->sucursalId($request);

        $ventas = Venta::where('estado', '!=', 'cancelada')
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->selectRaw('DATE(created_at) as fecha, COUNT(*) as total, SUM(total) as monto')
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->map(fn ($v) => [
                'fecha' => $v->fecha,
                'total' => (int) $v->total,
                'monto' => (float) $v->monto,
            ]);

        return response()->json($ventas);
    }

    public function productosTop(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin'    => 'nullable|date|after_or_equal:fecha_inicio',
            'limite'       => 'nullable|integer|min:1|max:50',
        ]);

        $inicio     = $request->filled('fecha_inicio') ? Carbon::parse($request->fecha_inicio)->startOfDay() : Carbon::now()->startOfMonth()->startOfDay();
        $fin        = $request->filled('fecha_fin')    ? Carbon::parse($request->fecha_fin)->endOfDay()      : Carbon::now()->endOfDay();
        $limite     = $request->integer('limite', 10);
        $sucursalId = $this->sucursalId($request);

        $top = VentaItem::selectRaw('nombre_producto, SUM(cantidad) as total_vendido, SUM(subtotal) as total_ingreso, AVG(precio_unitario) as precio_promedio')
            ->whereHas('venta', function ($q) use ($inicio, $fin, $sucursalId) {
                $q->where('estado', '!=', 'cancelada')
                  ->whereBetween('created_at', [$inicio, $fin])
                  ->when($sucursalId, fn ($q2) => $q2->where('sucursal_id', $sucursalId));
            })
            ->groupBy('nombre_producto')
            ->orderByDesc('total_ingreso')
            ->limit($limite)
            ->get()
            ->map(fn ($item) => [
                'producto'       => $item->nombre_producto,
                'vendidos'       => (int) $item->total_vendido,
                'ingreso'        => (float) $item->total_ingreso,
                'precio_promedio' => (float) round($item->precio_promedio, 2),
            ]);

        return response()->json($top);
    }

    public function categoriasReporte(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'nullable|date',
            'fecha_fin'    => 'nullable|date|after_or_equal:fecha_inicio',
        ]);

        $inicio     = $request->filled('fecha_inicio') ? Carbon::parse($request->fecha_inicio)->startOfDay() : Carbon::now()->startOfMonth()->startOfDay();
        $fin        = $request->filled('fecha_fin')    ? Carbon::parse($request->fecha_fin)->endOfDay()      : Carbon::now()->endOfDay();
        $sucursalId = $this->sucursalId($request);

        $categorias = VentaItem::selectRaw('categoria_id, SUM(cantidad) as total_vendido, SUM(subtotal) as total_ingreso')
            ->whereHas('venta', function ($q) use ($inicio, $fin, $sucursalId) {
                $q->where('estado', '!=', 'cancelada')
                  ->whereBetween('created_at', [$inicio, $fin])
                  ->when($sucursalId, fn ($q2) => $q2->where('sucursal_id', $sucursalId));
            })
            ->whereNotNull('categoria_id')
            ->groupBy('categoria_id')
            ->orderByDesc('total_ingreso')
            ->get()
            ->map(function ($item) {
                return [
                    'categoria_id' => $item->categoria_id,
                    'vendidos'     => (int) $item->total_vendido,
                    'ingreso'      => (float) $item->total_ingreso,
                ];
            });

        return response()->json($categorias);
    }

    public function stockBajo(Request $request)
    {
        $sucursalId = $this->sucursalId($request);

        $productos = Producto::where('activo', true)
            ->where('control_stock', true)
            ->whereColumn('stock', '<=', 'stock_minimo')
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->orderBy('stock')
            ->with('categoria')
            ->get()
            ->map(fn ($p) => [
                'id'       => $p->id,
                'nombre'   => $p->nombre,
                'stock'    => (int) $p->stock,
                'minimo'   => (int) $p->stock_minimo,
                'categoria' => $p->categoria?->nombre,
                'precio'   => (float) $p->precio,
            ]);

        $sinStock = Producto::where('activo', true)
            ->where('control_stock', true)
            ->where('stock', '<=', 0)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->orderBy('nombre')
            ->get()
            ->map(fn ($p) => [
                'id'     => $p->id,
                'nombre' => $p->nombre,
                'stock'  => 0,
                'minimo' => (int) $p->stock_minimo,
                'precio' => (float) $p->precio,
            ]);

        return response()->json([
            'bajo_stock' => $productos,
            'sin_stock'  => $sinStock,
        ]);
    }
}
