<?php

namespace App\Http\Controllers\Api;

use App\Models\Proveedor;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProveedorController extends Controller
{
    public function index(Request $request)
    {
        $query = Proveedor::query()->latest();

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('contacto', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('rfc', 'like', "%{$search}%")
                  ->orWhere('telefono', 'like', "%{$search}%");
            });
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'   => ['required', 'string', 'max:255'],
            'contacto' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'email'    => ['nullable', 'email', 'max:255'],
            'rfc'      => ['nullable', 'string', 'max:20'],
            'notas'    => ['nullable', 'string'],
        ]);

        $data['activo'] = $request->boolean('activo', true);

        return response()->json(Proveedor::create($data), 201);
    }

    public function show(Proveedor $proveedor)
    {
        return response()->json($proveedor->load('productos'));
    }

    public function update(Request $request, Proveedor $proveedor)
    {
        $data = $request->validate([
            'nombre'   => ['sometimes', 'string', 'max:255'],
            'contacto' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'email'    => ['nullable', 'email', 'max:255'],
            'rfc'      => ['nullable', 'string', 'max:20'],
            'notas'    => ['nullable', 'string'],
            'activo'   => ['sometimes', 'boolean'],
        ]);

        $proveedor->update($data);

        return response()->json($proveedor);
    }

    public function destroy(Proveedor $proveedor)
    {
        if ($proveedor->productos()->exists()) {
            return response()->json(
                ['message' => 'No se puede eliminar un proveedor con productos asociados'],
                422
            );
        }

        $proveedor->delete();

        return response()->json(['message' => 'Proveedor eliminado']);
    }

    public function toggle(Proveedor $proveedor)
    {
        $proveedor->update(['activo' => !$proveedor->activo]);

        return response()->json($proveedor);
    }
}
