<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Plan::withCount('empresas')->orderBy('precio_mensual')->get(),
        ]);
    }

    public function show(Plan $plan)
    {
        return response()->json(['data' => $plan->load('empresas:id,nombre,email')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'             => 'required|string|max:100',
            'descripcion'        => 'nullable|string',
            'precio_mensual'     => 'required|numeric|min:0',
            'max_sucursales'     => 'required|integer|min:-1',
            'max_usuarios'       => 'required|integer|min:-1',
            'timbres_incluidos'  => 'nullable|integer|min:-1',
            'modulos'            => 'nullable|array',
            'modulos.*'          => 'string',
            'color'              => 'nullable|string|max:20',
            'stripe_price_id'      => 'nullable|string|max:100',
            'stripe_price_id_anual'=> 'nullable|string|max:100',
            'tipo'                 => 'nullable|in:stripe,manual',
            'periodicidad'         => 'nullable|in:mensual,unico',
            'activo'               => 'boolean',
        ]);

        $plan = Plan::create($data);

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'nombre'             => 'sometimes|string|max:100',
            'descripcion'        => 'nullable|string',
            'precio_mensual'     => 'sometimes|numeric|min:0',
            'max_sucursales'     => 'sometimes|integer|min:-1',
            'max_usuarios'       => 'sometimes|integer|min:-1',
            'timbres_incluidos'  => 'nullable|integer|min:-1',
            'modulos'            => 'nullable|array',
            'modulos.*'          => 'string',
            'color'              => 'nullable|string|max:20',
            'stripe_price_id'      => 'nullable|string|max:100',
            'stripe_price_id_anual'=> 'nullable|string|max:100',
            'tipo'                 => 'nullable|in:stripe,manual',
            'periodicidad'         => 'nullable|in:mensual,unico',
            'activo'               => 'boolean',
        ]);

        $plan->update($data);

        return response()->json(['data' => $plan]);
    }

    public function destroy(Plan $plan)
    {
        // Desasociar empresas antes de eliminar y dejarlas vencidas hasta reasignar plan.
        $plan->empresas()->update([
            'plan_id'             => null,
            'plan_estado'         => 'sin_plan',
            'plan_vigente_hasta'  => now(),
            'plan_precio_pactado' => null,
        ]);

        foreach ($plan->empresas as $empresa) {
            $empresa->modulos()->update(['activo' => false]);
        }

        $plan->delete();

        return response()->json(['message' => 'Plan eliminado']);
    }
}
