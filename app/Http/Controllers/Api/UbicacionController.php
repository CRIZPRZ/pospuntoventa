<?php

namespace App\Http\Controllers\Api;

use App\Models\Ubicacion;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesBySucursal;
use Illuminate\Http\Request;

class UbicacionController extends Controller
{
    use ScopesBySucursal;

    public function index(Request $request)
    {
        $allowed  = ['descripcion', 'codigo', 'ubicacion', 'precio', 'cantidad', 'id'];
        $sortBy   = in_array($request->input('sort_by'), $allowed) ? $request->input('sort_by') : 'id';
        $sortDir  = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';

        $query = $this->applySucursalScope(Ubicacion::query()->orderBy($sortBy, $sortDir));

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($q) use ($search) {
                $q->where('descripcion', 'like', "%{$search}%")
                  ->orWhere('codigo', 'like', "%{$search}%")
                  ->orWhere('ubicacion', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function ajustarCantidad(Request $request, Ubicacion $ubicacion)
    {
        $data = $request->validate(['delta' => ['required', 'integer']]);
        $nueva = max(0, ($ubicacion->cantidad ?? 0) + $data['delta']);
        $ubicacion->update(['cantidad' => $nueva]);
        return response()->json($ubicacion);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'descripcion' => ['required', 'string', 'max:255'],
            'codigo'      => ['nullable', 'string', 'max:100'],
            'ubicacion'   => ['nullable', 'string', 'max:100'],
            'precio'      => ['nullable', 'numeric', 'min:0'],
            'cantidad'    => ['nullable', 'integer', 'min:0'],
        ]);

        $data['activo']      = $request->boolean('activo', true);
        $data['sucursal_id'] = $this->sucursalId();

        return response()->json(Ubicacion::create($data), 201);
    }

    public function show(Ubicacion $ubicacion)
    {
        return response()->json($ubicacion);
    }

    public function update(Request $request, Ubicacion $ubicacion)
    {
        $data = $request->validate([
            'descripcion' => ['sometimes', 'string', 'max:255'],
            'codigo'      => ['nullable', 'string', 'max:100'],
            'ubicacion'   => ['nullable', 'string', 'max:100'],
            'precio'      => ['nullable', 'numeric', 'min:0'],
            'cantidad'    => ['nullable', 'integer', 'min:0'],
            'activo'      => ['sometimes', 'boolean'],
        ]);

        $ubicacion->update($data);

        return response()->json($ubicacion);
    }

    public function destroy(Ubicacion $ubicacion)
    {
        $ubicacion->delete();

        return response()->json(['message' => 'Ubicación eliminada']);
    }

    public function toggle(Ubicacion $ubicacion)
    {
        $ubicacion->update(['activo' => !$ubicacion->activo]);

        return response()->json($ubicacion);
    }

    public function importar(Request $request)
    {
        $request->validate([
            'ubicaciones'                   => ['required', 'array', 'min:1', 'max:5000'],
            'ubicaciones.*.descripcion'     => ['required', 'string', 'max:255'],
            'ubicaciones.*.codigo'          => ['nullable', 'string', 'max:100'],
            'ubicaciones.*.ubicacion'       => ['nullable', 'string', 'max:100'],
            'ubicaciones.*.precio'          => ['nullable', 'numeric', 'min:0'],
            'ubicaciones.*.cantidad'        => ['nullable', 'integer', 'min:0'],
        ]);

        $creados      = 0;
        $actualizados = 0;
        $errores      = [];
        $sucursalId   = $this->sucursalId();

        foreach ($request->ubicaciones as $idx => $item) {
            try {
                $payload = [
                    'descripcion' => $item['descripcion'],
                    'codigo'      => $item['codigo'] ?? null,
                    'ubicacion'   => $item['ubicacion'] ?? null,
                    'precio'      => $item['precio'] ?? null,
                    'cantidad'    => isset($item['cantidad']) ? (int) $item['cantidad'] : null,
                    'activo'      => true,
                ];

                if (!empty($item['codigo'])) {
                    $existente = Ubicacion::where('codigo', $item['codigo'])
                        ->where('sucursal_id', $sucursalId)
                        ->first();

                    if ($existente) {
                        $existente->update($payload);
                        $actualizados++;
                        continue;
                    }
                }

                $payload['sucursal_id'] = $sucursalId;
                Ubicacion::create($payload);
                $creados++;
            } catch (\Throwable $e) {
                $errores[] = [
                    'fila'    => $idx + 2,
                    'nombre'  => $item['descripcion'] ?? '',
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'creados'      => $creados,
            'actualizados' => $actualizados,
            'errores'      => $errores,
        ]);
    }
}
