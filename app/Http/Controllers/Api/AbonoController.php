<?php

namespace App\Http\Controllers\Api;

use App\Models\Abono;
use App\Models\Venta;
use App\Models\Cliente;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesBySucursal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AbonoController extends Controller
{
    use ScopesBySucursal;

    public function index(Request $request)
    {
        $query = $this->applySucursalScope(
            Abono::with(['venta', 'cliente'])->latest()
        );

        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->cliente_id);
        }

        if ($request->filled('venta_id')) {
            $query->where('venta_id', $request->venta_id);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'venta_id' => ['nullable', 'exists:ventas,id'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'metodo_pago' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'referencia' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $cliente = Cliente::lockForUpdate()->findOrFail($data['cliente_id']);

            if ($data['venta_id'] ?? null) {
                $venta = Venta::findOrFail($data['venta_id']);

                if ($venta->tipo_pago !== 'credito') {
                    abort(422, 'La venta no es a crédito');
                }

                if ($venta->estado === 'cancelada') {
                    abort(422, 'No se pueden hacer abonos a una venta cancelada');
                }

                if ($venta->cliente_id !== $cliente->id) {
                    abort(422, 'La venta no pertenece a este cliente');
                }
            }

            $abono = Abono::create([
                'venta_id' => $data['venta_id'] ?? null,
                'cliente_id' => $cliente->id,
                'monto' => $data['monto'],
                'metodo_pago' => $data['metodo_pago'],
                'referencia' => $data['referencia'] ?? null,
                'notas' => $data['notas'] ?? null,
            ]);

            $cliente->decrement('saldo_credito', $data['monto']);

            return $abono->load(['venta', 'cliente']);
        });

        return response()->json($result, 201);
    }

    public function resumen(Cliente $cliente)
    {
        $ventasCredito = Venta::where('cliente_id', $cliente->id)
            ->where('tipo_pago', 'credito')
            ->where('estado', '!=', 'cancelada')
            ->with('abonos')
            ->latest()
            ->get();

        $totalVentas = $ventasCredito->sum('total');
        $totalAbonos = $cliente->abonos()->sum('monto');
        $saldoPendiente = (float) $cliente->saldo_credito;
        $limiteCredito = (float) $cliente->limite_credito;
        $disponible = max(0, $limiteCredito - $saldoPendiente);

        return response()->json([
            'cliente' => $cliente,
            'limite_credito' => $limiteCredito,
            'saldo_credito' => $saldoPendiente,
            'credito_disponible' => $disponible,
            'total_ventas_credito' => $totalVentas,
            'total_abonos' => $totalAbonos,
            'ventas_pendientes' => $ventasCredito->map(function ($venta) {
                $totalAbonado = $venta->abonos->sum('monto');
                return [
                    'id' => $venta->id,
                    'folio' => $venta->folio,
                    'total' => (float) $venta->total,
                    'abonado' => (float) $totalAbonado,
                    'pendiente' => (float) $venta->total - $totalAbonado,
                    'fecha' => $venta->created_at,
                ];
            }),
        ]);
    }
}
