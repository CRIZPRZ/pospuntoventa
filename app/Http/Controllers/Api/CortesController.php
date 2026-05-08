<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CortesController extends Controller
{
    public function hoy(Request $request)
    {
        $fecha    = $request->get('fecha', now()->format('Y-m-d'));
        $cajeroId = $request->get('cajero_id', $request->get('cajero'));

        $query = DB::table('ventas')
            ->join('users', 'ventas.user_id', '=', 'users.id')
            ->whereDate('ventas.created_at', $fecha)
            ->where('ventas.estado', '!=', 'cancelada');

        if ($cajeroId) {
            $query->where('ventas.user_id', $cajeroId);
        }

        $ventas = $query->select(
            'ventas.*',
            'users.name as cajero_nombre'
        )->get();

        // Sumar desde pagos por metodo — más preciso que ventas.tipo_pago,
        // soporta pagos mixtos y evita problemas de coincidencia de strings en colección.
        $pagosQuery = DB::table('pagos')
            ->join('ventas as vp', 'vp.id', '=', 'pagos.venta_id')
            ->whereDate('vp.created_at', $fecha)
            ->where('vp.estado', '!=', 'cancelada');

        if ($cajeroId) {
            $pagosQuery->where('vp.user_id', $cajeroId);
        }

        $pagosPorMetodo = $pagosQuery
            ->select('pagos.metodo', DB::raw('SUM(pagos.monto) as total'))
            ->groupBy('pagos.metodo')
            ->pluck('total', 'metodo');

        $totalEfectivo = (float) ($pagosPorMetodo['efectivo'] ?? 0);
        $totalTarjeta  = (float) ($pagosPorMetodo['tarjeta'] ?? 0);
        $totalCredito  = (float) ($pagosPorMetodo['credito'] ?? 0);

        // Buscar caja del día sin filtrar por estado — puede estar cerrada al hacer el corte
        $cajaQuery = DB::table('cajas')->whereDate('abierta_at', $fecha);
        if ($cajeroId) {
            $cajaQuery->where('user_id', $cajeroId);
        }
        $caja = $cajaQuery->latest('abierta_at')->first();

        $fondoInicial = $caja ? (float) $caja->fondo_inicial : 0;

        // Pagos de clientes (abonos) del día
        $pagosClientes = DB::table('abonos')
            ->whereDate('created_at', $fecha)
            ->sum('monto');

        // Pagos a proveedores del día (salidas de efectivo)
        $pagosProveedores = 0;
        if (DB::getSchemaBuilder()->hasTable('pagos_proveedores')) {
            $pagosProveedores = DB::table('pagos_proveedores')
                ->whereDate('created_at', $fecha)
                ->sum('monto');
        }

        // Ventas agrupadas por proveedor (join venta_items → productos → proveedores)
        $ventasPorProveedorQuery = DB::table('venta_items')
            ->join('ventas as v2', 'v2.id', '=', 'venta_items.venta_id')
            ->join('productos', 'productos.id', '=', 'venta_items.producto_id')
            ->leftJoin('proveedores', 'proveedores.id', '=', 'productos.proveedor_id')
            ->whereDate('v2.created_at', $fecha)
            ->where('v2.estado', '!=', 'cancelada');

        if ($cajeroId) {
            $ventasPorProveedorQuery->where('v2.user_id', $cajeroId);
        }

        $ventasPorDepto = $ventasPorProveedorQuery
            ->select(
                DB::raw('COALESCE(proveedores.nombre, "SIN PROVEEDOR") as nombre'),
                DB::raw('SUM(venta_items.subtotal) as monto')
            )
            ->groupBy('proveedores.id', 'proveedores.nombre')
            ->get()
            ->map(fn($row) => ['nombre' => $row->nombre, 'monto' => $row->monto])
            ->values();

        $cajeroNombre = $cajeroId
            ? ($ventas->first()->cajero_nombre ?? 'N/A')
            : 'TODOS';

        $totalDineroCaja = $fondoInicial + $totalEfectivo + $pagosClientes - $pagosProveedores;

        return response()->json([
            'id'         => null,
            'fecha'      => $fecha,
            'hora'       => now()->format('h:i A'),
            'num_ventas' => $ventas->count(),
            'cajero'     => $cajeroNombre,
            'caja'   => $caja->nombre ?? 'Caja Principal',
            'entradas_efectivo' => [
                'inicio_caja'    => $fondoInicial,
                'entrada_cambio' => 0.00,
                'total'          => $fondoInicial,
            ],
            'dinero_caja' => [
                'pagos_efectivo'    => $totalEfectivo,
                'entradas_efectivo' => $fondoInicial,
                'pagos_clientes'    => $pagosClientes,
                'pago_proveedores'  => $pagosProveedores,
                'total'             => $totalDineroCaja,
            ],
            'ventas_contado' => [
                'efectivo' => $totalEfectivo,
                'tarjeta'  => $totalTarjeta,
                'vales'    => 0.00,
                'credito'  => $totalCredito,
                'total'    => $totalEfectivo + $totalTarjeta,
            ],
            'ventas_credito' => [
                'total' => $totalCredito,
            ],
            'ventas_departamento' => $ventasPorDepto,
            'pagos_clientes'      => $pagosClientes,
            'pagos_proveedores'   => $pagosProveedores,
            'ventas_totales'      => $totalEfectivo + $totalTarjeta + $totalCredito,
        ]);
    }

    public function generar(Request $request)
    {
        $request->validate([
            'fecha'     => 'required|date',
            'cajero_id' => 'nullable',
        ]);

        return response()->json([
            'message' => 'Corte generado exitosamente',
            'id'      => uniqid('corte_'),
        ]);
    }

    public function ticket($id)
    {
        $corte = $this->hoy(request())->original;

        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Corte del Día</title>
            <style>
                body { font-family: monospace; width: 300px; margin: 0 auto; padding: 20px; }
                .center { text-align: center; }
                .bold { font-weight: bold; }
                .line { border-top: 1px dashed #000; margin: 10px 0; }
                table { width: 100%; border-collapse: collapse; }
                td { padding: 2px 0; }
                .right { text-align: right; }
            </style>
        </head>
        <body>
            <div class="center bold">CORTE DEL DIA</div>
            <div class="center">' . $corte['fecha'] . '</div>
            <div class="center">REALIZADO: ' . now()->format('d/m/Y h:i A') . '</div>
            <div class="line"></div>
            <div>CAJERO: ' . $corte['cajero'] . '</div>
            <div>CAJA: ' . $corte['caja'] . '</div>
            <div class="line"></div>

            <div class="bold">ENTRADAS DE EFECTIVO</div>
            <table>
                <tr><td>Inicio Caja:</td><td class="right">$' . number_format($corte['entradas_efectivo']['inicio_caja'], 2) . '</td></tr>
                <tr><td>Entrada Cambio:</td><td class="right">$' . number_format($corte['entradas_efectivo']['entrada_cambio'], 2) . '</td></tr>
                <tr><td class="bold">Total:</td><td class="right bold">$' . number_format($corte['entradas_efectivo']['total'], 2) . '</td></tr>
            </table>

            <div class="line"></div>
            <div class="bold">DINERO EN CAJA</div>
            <table>
                <tr><td>Ventas Efectivo: +</td><td class="right">$' . number_format($corte['dinero_caja']['pagos_efectivo'], 2) . '</td></tr>
                <tr><td>Entradas: +</td><td class="right">$' . number_format($corte['dinero_caja']['entradas_efectivo'], 2) . '</td></tr>
                <tr><td>Pagos Clientes: +</td><td class="right">$' . number_format($corte['dinero_caja']['pagos_clientes'], 2) . '</td></tr>
                <tr><td>Pago Proveedores: -</td><td class="right">$' . number_format($corte['dinero_caja']['pago_proveedores'], 2) . '</td></tr>
                <tr><td class="bold">Total:</td><td class="right bold">$' . number_format($corte['dinero_caja']['total'], 2) . '</td></tr>
            </table>

            <div class="line"></div>
            <div class="bold">VENTAS DE CONTADO</div>
            <table>
                <tr><td>Efectivo:</td><td class="right">$' . number_format($corte['ventas_contado']['efectivo'], 2) . '</td></tr>
                <tr><td>Tarjeta:</td><td class="right">$' . number_format($corte['ventas_contado']['tarjeta'], 2) . '</td></tr>
                <tr><td class="bold">Total:</td><td class="right bold">$' . number_format($corte['ventas_contado']['total'], 2) . '</td></tr>
            </table>

            <div class="line"></div>
            <div class="bold">PAGOS DE CLIENTES</div>
            <div>Abonos del día: $' . number_format($corte['pagos_clientes'], 2) . '</div>

            <div class="line"></div>
            <div class="bold">PAGOS A PROVEEDORES (SALIDAS)</div>
            <div>Total salidas: $' . number_format($corte['pagos_proveedores'], 2) . '</div>

            <div class="line"></div>
            <div class="bold">VENTAS POR DEPARTAMENTO</div>
            <table>';

        foreach ($corte['ventas_departamento'] as $item) {
            $html .= '<tr><td>' . $item['codigo'] . '</td><td class="right">$' . number_format($item['monto'], 2) . '</td></tr>';
        }

        $html .= '
            </table>
            <div class="line"></div>
            <div class="center bold">VENTAS TOTALES: $' . number_format($corte['ventas_totales'], 2) . '</div>
            <div class="line"></div>
            <div class="center">*** FIN DEL CORTE ***</div>
        </body>
        </html>';

        return response()->json(['ticket_html' => $html]);
    }
}
