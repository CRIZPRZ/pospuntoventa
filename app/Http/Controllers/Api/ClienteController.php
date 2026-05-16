<?php

namespace App\Http\Controllers\Api;

use App\Models\Cliente;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesBySucursal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    use ScopesBySucursal;
    public function index(Request $request)
    {
        $query = $this->applySucursalScope(Cliente::query()->latest());

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
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
            'nombre'         => ['required', 'string', 'max:255'],
            'email'          => ['nullable', 'email', 'max:255'],
            'telefono'       => ['nullable', 'string', 'max:50'],
            'rfc'            => ['nullable', 'string', 'max:20'],
            'direccion'      => ['nullable', 'string', 'max:500'],
            'limite_credito' => ['required', 'numeric', 'min:0'],
            'codigo_postal'  => ['nullable', 'string', 'max:10'],
            'regimen_fiscal' => ['nullable', 'string', 'max:10'],
            'uso_cfdi'       => ['nullable', 'string', 'max:10'],
        ]);

        $data['activo'] = $request->boolean('activo', true);
        $data['saldo_credito'] = 0;
        $data['sucursal_id'] = $this->sucursalId();

        $cliente = Cliente::create($data);

        return response()->json($cliente, 201);
    }

    public function show(Cliente $cliente)
    {
        return response()->json($cliente->load(['ventas' => function ($q) {
            $q->latest()->limit(10);
        }]));
    }

    public function update(Request $request, Cliente $cliente)
    {
        $data = $request->validate([
            'nombre'         => ['sometimes', 'string', 'max:255'],
            'email'          => ['nullable', 'email', 'max:255'],
            'telefono'       => ['nullable', 'string', 'max:50'],
            'rfc'            => ['nullable', 'string', 'max:20'],
            'direccion'      => ['nullable', 'string', 'max:500'],
            'limite_credito' => ['sometimes', 'numeric', 'min:0'],
            'activo'         => ['sometimes', 'boolean'],
            'codigo_postal'  => ['nullable', 'string', 'max:10'],
            'regimen_fiscal' => ['nullable', 'string', 'max:10'],
            'uso_cfdi'       => ['nullable', 'string', 'max:10'],
        ]);

        $cliente->update($data);

        return response()->json($cliente);
    }

    public function destroy(Cliente $cliente)
    {
        if ($cliente->saldo_credito > 0) {
            return response()->json(['message' => 'No se puede eliminar un cliente con saldo pendiente'], 422);
        }

        $cliente->delete();

        return response()->json(['message' => 'Cliente eliminado']);
    }
}
