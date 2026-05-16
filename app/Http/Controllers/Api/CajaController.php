<?php

namespace App\Http\Controllers\Api;

use App\Models\Caja;
use App\Models\Sucursal;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesBySucursal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CajaController extends Controller
{
    use ScopesBySucursal;

    private function modoCaja(): string
    {
        $sucursalId = $this->sucursalId();
        if (!$sucursalId) return 'individual';
        $sucursal = Sucursal::find($sucursalId);
        return $sucursal?->modo_caja ?? 'individual';
    }

    public function actual(Request $request)
    {
        $cajaQuery = Caja::with(['user', 'cerradaPor'])->where('estado', 'abierta');

        if ($this->modoCaja() === 'compartida') {
            $this->applySucursalScope($cajaQuery);
        } else {
            $cajaQuery->where('user_id', $request->user()->id);
            $this->applySucursalScope($cajaQuery);
        }

        $caja = $cajaQuery->latest('abierta_at')->first();

        if (! $caja) {
            return response()->json(['message' => 'No hay caja abierta'], 404);
        }

        return response()->json($caja);
    }

    public function abrir(Request $request)
    {
        $data = $request->validate([
            'fondo_inicial'  => ['required', 'numeric', 'min:0'],
            'notas'          => ['nullable', 'string'],
            'observaciones'  => ['nullable', 'string'],
        ]);

        if ($this->modoCaja() === 'compartida') {
            // Verificar que no haya caja abierta en esta sucursal (por cualquier usuario)
            $cajaQ = Caja::where('estado', 'abierta');
            $this->applySucursalScope($cajaQ);
            if ($cajaQ->exists()) {
                return response()->json(['message' => 'Ya hay una caja abierta en esta sucursal'], 422);
            }
        } else {
            // Verificar solo la caja del usuario actual
            $abierta = Caja::where('user_id', $request->user()->id)
                ->where('estado', 'abierta')
                ->exists();
            if ($abierta) {
                return response()->json(['message' => 'Ya tienes una caja abierta'], 422);
            }
        }

        $caja = Caja::create([
            'user_id'       => $request->user()->id,
            'sucursal_id'   => $this->sucursalId(),
            'fondo_inicial' => $data['fondo_inicial'],
            'abierta_at'    => now(),
            'estado'        => 'abierta',
            'observaciones' => $data['observaciones'] ?? $data['notas'] ?? null,
        ]);

        return response()->json($caja, 201);
    }

    public function cerrar(Request $request)
    {
        $data = $request->validate([
            'efectivo_contado' => ['required', 'numeric', 'min:0'],
            'notas'            => ['nullable', 'string'],
            'observaciones'    => ['nullable', 'string'],
        ]);

        if ($this->modoCaja() === 'compartida') {
            // Cierra la caja abierta de la sucursal (cualquier usuario puede cerrarla)
            $cajaQ = Caja::where('estado', 'abierta')->latest('abierta_at');
            $this->applySucursalScope($cajaQ);
            $caja = $cajaQ->first();
        } else {
            $caja = Caja::where('user_id', $request->user()->id)
                ->where('estado', 'abierta')
                ->latest('abierta_at')
                ->first();
        }

        if (! $caja) {
            return response()->json(['message' => 'No hay caja abierta para cerrar'], 404);
        }

        $userId = $request->user()->id;

        $caja = DB::transaction(function () use ($caja, $data, $userId) {
            $esperado        = (float) $caja->fondo_inicial + (float) $caja->total_efectivo;
            $efectivoContado = (float) $data['efectivo_contado'];
            $observaciones   = collect([
                $caja->observaciones,
                $data['observaciones'] ?? $data['notas'] ?? null,
                'Efectivo contado: $' . number_format($efectivoContado, 2, '.', ''),
            ])->filter()->implode("\n");

            $caja->update([
                'cerrada_at'       => now(),
                'cerrada_por'      => $userId,
                'estado'           => 'cerrada',
                'efectivo_contado' => $efectivoContado,
                'diferencia'       => $efectivoContado - $esperado,
                'observaciones'    => $observaciones,
            ]);

            return $caja->fresh(['user', 'cerradaPor']);
        });

        return response()->json($caja);
    }

    public function cortes(Request $request)
    {
        $query = $this->applySucursalScope(Caja::with(['user', 'cerradaPor'])->latest('abierta_at'));

        if (! $request->user()->hasRole('admin')) {
            $query->where('user_id', $request->user()->id);
        }

        $cortes = $query->paginate($request->integer('per_page', 50));

        $cortes->getCollection()->transform(function ($corte) {
            if ($corte->cerrada_por) {
                $corte->setAttribute('cerrada_por_nombre', $corte->cerradaPor?->name);
            }
            return $corte;
        });

        return response()->json($cortes);
    }

    public function corte(Caja $caja)
    {
        return response()->json($caja->load(['user', 'cerradaPor', 'ventas.items', 'ventas.pagos']));
    }
}
