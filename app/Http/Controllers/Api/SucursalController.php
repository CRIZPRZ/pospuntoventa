<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class SucursalController extends Controller
{
    public function index()
    {
        $sucursales = Sucursal::withCount('usuarios')->get();
        return response()->json($sucursales);
    }

    public function show(Sucursal $sucursal)
    {
        return response()->json($sucursal->load('usuarios'));
    }

    public function store(Request $request)
    {
        $empresa = $request->user()?->empresa?->loadMissing('plan');
        if (! $empresa) {
            return response()->json(['message' => 'Empresa no encontrada'], 422);
        }

        $limiteSucursales = $empresa->limiteSucursales();
        $sucursalesActivas = Sucursal::where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->count();

        if ($limiteSucursales !== -1 && $sucursalesActivas >= $limiteSucursales) {
            return response()->json([
                'message' => "Tu plan permite hasta {$limiteSucursales} sucursal(es). Actualiza tu plan para agregar otra.",
            ], 422);
        }

        $data = $request->validate([
            'nombre'       => 'required|string|max:120',
            'direccion'    => 'nullable|string|max:255',
            'telefono'     => 'nullable|string|max:30',
            'ciudad'       => 'nullable|string|max:100',
            'mediamtx_url' => 'nullable|url|max:255',
        ]);

        $data['agent_token'] = \Illuminate\Support\Str::random(48);
        $sucursal = Sucursal::create($data);

        // Auto-asignar al admin actual con rol admin en la nueva sucursal
        $tenantId = app()->bound('tenant_id') ? app('tenant_id') : null;
        if ($tenantId) {
            $adminRole = Role::where('empresa_id', $tenantId)->where('name', 'admin')->first();
            if ($adminRole) {
                DB::table('usuario_sucursal')->insertOrIgnore([
                    'user_id'     => auth()->id(),
                    'sucursal_id' => $sucursal->id,
                    'role_id'     => $adminRole->id,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }

        return response()->json($sucursal, 201);
    }

    public function update(Request $request, Sucursal $sucursal)
    {
        $data = $request->validate([
            'nombre'       => 'sometimes|string|max:120',
            'direccion'    => 'nullable|string|max:255',
            'telefono'     => 'nullable|string|max:30',
            'ciudad'       => 'nullable|string|max:100',
            'activo'       => 'sometimes|boolean',
            'modo_caja'    => 'sometimes|in:individual,compartida',
            'mediamtx_url' => 'nullable|url|max:255',
        ]);

        $sucursal->update($data);

        return response()->json($sucursal);
    }

    public function destroy(Sucursal $sucursal)
    {
        if ($sucursal->es_principal) {
            return response()->json(['message' => 'No se puede eliminar la sucursal principal'], 422);
        }

        $total = Sucursal::count();
        if ($total <= 1) {
            return response()->json(['message' => 'Debe existir al menos una sucursal'], 422);
        }

        $sucursal->delete();

        return response()->json(['message' => 'Sucursal eliminada']);
    }

    // --- Usuarios de la sucursal ---

    public function usuarios(Sucursal $sucursal)
    {
        $usuarios = DB::table('usuario_sucursal as us')
            ->join('users', 'users.id', '=', 'us.user_id')
            ->join('roles', 'roles.id', '=', 'us.role_id')
            ->where('us.sucursal_id', $sucursal->id)
            ->select('users.id', 'users.name', 'users.email', 'roles.name as rol', 'us.role_id', 'us.id as pivot_id')
            ->get();

        return response()->json($usuarios);
    }

    public function addUsuario(Request $request, Sucursal $sucursal)
    {
        $tenantId = app('tenant_id');

        $roles = Role::where('empresa_id', $tenantId)->pluck('name')->toArray();

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'rol'     => ['required', \Illuminate\Validation\Rule::in($roles)],
        ]);

        $user = User::findOrFail($data['user_id']);

        $role = Role::where('empresa_id', $tenantId)
                    ->where('name', $data['rol'])
                    ->firstOrFail();

        DB::table('usuario_sucursal')->updateOrInsert(
            ['user_id' => $user->id, 'sucursal_id' => $sucursal->id],
            ['role_id' => $role->id, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['message' => 'Usuario agregado a sucursal']);
    }

    public function removeUsuario(Sucursal $sucursal, User $usuario)
    {
        DB::table('usuario_sucursal')
            ->where('sucursal_id', $sucursal->id)
            ->where('user_id', $usuario->id)
            ->delete();

        return response()->json(['message' => 'Usuario removido de sucursal']);
    }

    // --- Importar catálogo de otra sucursal ---

    public function importarCatalogo(Request $request, Sucursal $sucursal)
    {
        $data = $request->validate([
            'origen_sucursal_id' => 'required|exists:sucursales,id',
        ]);

        $tenantId = app('tenant_id');

        // Verificar que la sucursal origen pertenece a la misma empresa
        $origen = Sucursal::withoutGlobalScopes()
            ->where('id', $data['origen_sucursal_id'])
            ->where('empresa_id', $tenantId)
            ->firstOrFail();

        // Obtener productos de la sucursal origen (o todos los de la empresa si no tienen registro)
        $productosOrigen = DB::table('producto_sucursal')
            ->where('sucursal_id', $origen->id)
            ->get();

        $importados = 0;

        foreach ($productosOrigen as $ps) {
            $existe = DB::table('producto_sucursal')
                ->where('producto_id', $ps->producto_id)
                ->where('sucursal_id', $sucursal->id)
                ->exists();

            if (!$existe) {
                DB::table('producto_sucursal')->insert([
                    'producto_id'   => $ps->producto_id,
                    'sucursal_id'   => $sucursal->id,
                    'stock'         => 0,
                    'stock_minimo'  => $ps->stock_minimo,
                    'precio'        => null, // usa precio base
                    'precio_mayoreo'=> null,
                    'activo'        => true,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
                $importados++;
            }
        }

        return response()->json(['importados' => $importados]);
    }
}
