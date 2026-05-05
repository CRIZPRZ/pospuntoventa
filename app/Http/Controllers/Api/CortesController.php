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
            ->leftJoin('cajas', 'ventas.caja_id', '=', 'cajas.id')
            ->whereDate('ventas.created_at', $fecha)
            ->where('ventas.estado', '!=', 'cancelada');

        if ($cajeroId) {
            $query->where('ventas.user_id', $cajeroId);
        }

        $ventas = $query->select(
            'ventas.*',
            'users.name as cajero_nombre',
            'cajas.fondo_inicial'
        )->get();

        $totalEfectivo = $ventas->where('tipo_pago', 'efectivo')->sum('total');
        $totalTarjeta  = $ventas->where('tipo_pago', 'tarjeta')->sum('total');
        $totalCredito  = $ventas->where('tipo_pago', 'credito')->sum('total');

        $caja = DB::table('cajas')
            ->whereDate('created_at', $fecha)
            ->where('estado', 'abierta')
            ->first();

        $fondoInicial = $caja ? $caja->fondo_inicial : 0;

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

        $ventasPorDepto = $ventas->groupBy('codigo_departamento')
            ->map(function ($items, $codigo) {
                return [
                    'codigo' => $codigo ?? 'SIN-DEPTO',
                    'monto'  => $items->sum('total'),
                ];
            })->values();

        $cajeroNombre = $cajeroId
            ? ($ventas->first()->cajero_nombre ?? 'N/A')
            : 'TODOS';

        $totalDineroCaja = $fondoInicial + $totalEfectivo + $pagosClientes - $pagosProveedores;

        return response()->json([
            'id'     => null,
            'fecha'  => $fecha,
            'hora'   => now()->format('h:i A'),
            'cajero' => $cajeroNombre,
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
