<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesBySucursal;
use App\Models\PagoProveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PagoProveedorController extends Controller
{
    use ScopesBySucursal;

    public function index(Request $request)
    {
        $query = $this->applySucursalScope(
            PagoProveedor::with('proveedor:id,nombre', 'usuario:id,name')->orderByDesc('created_at')
        );

        if ($request->filled('fecha')) {
            $query->whereDate('created_at', $request->fecha);
        }

        if ($request->filled('proveedor_id')) {
            $query->where('proveedor_id', $request->proveedor_id);
        }

        if ($request->filled('metodo_pago')) {
            $query->where('metodo_pago', $request->metodo_pago);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'proveedor_id' => 'nullable|exists:proveedores,id',
            'concepto'     => 'required|string|max:255',
            'monto'        => 'required|numeric|min:0.01',
            'metodo_pago'  => 'required|in:efectivo,tarjeta,transferencia',
            'referencia'   => 'nullable|string|max:255',
            'notas'        => 'nullable|string',
        ]);

        $data['user_id'] = Auth::id();

        $pago = PagoProveedor::create($data);
        $pago->load('proveedor:id,nombre', 'usuario:id,name');

        return response()->json(['data' => $pago], 201);
    }

    public function update(Request $request, PagoProveedor $pagoProveedor)
    {
        $data = $request->validate([
            'proveedor_id' => 'nullable|exists:proveedores,id',
            'concepto'     => 'required|string|max:255',
            'monto'        => 'required|numeric|min:0.01',
            'metodo_pago'  => 'required|in:efectivo,tarjeta,transferencia',
            'referencia'   => 'nullable|string|max:255',
            'notas'        => 'nullable|string',
        ]);

        $pagoProveedor->update($data);
        $pagoProveedor->load('proveedor:id,nombre', 'usuario:id,name');

        return response()->json(['data' => $pagoProveedor]);
    }

    public function destroy(PagoProveedor $pagoProveedor)
    {
        $pagoProveedor->delete();
        return response()->json(['message' => 'Pago eliminado']);
    }
}
