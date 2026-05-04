<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;

class RolPermisoController extends Controller
{
    public function index()
    {
        $usersCount = DB::table('model_has_roles')
            ->select('role_id', DB::raw('count(*) as count'))
            ->groupBy('role_id')
            ->pluck('count', 'role_id');

        $roles = Role::with('permissions')
            ->latest()
            ->get()
            ->map(function ($role) use ($usersCount) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'label' => ucfirst($role->name),
                    'permissions' => $role->permissions->pluck('name'),
                    'users_count' => $usersCount->get($role->id, 0),
                    'created_at' => $role->created_at->format('Y-m-d H:i'),
                ];
            });

        return response()->json($roles);
    }

    public function permisos()
    {
        $grupos = [
            'Dashboard' => ['ver dashboard'],
            'Productos' => ['ver productos', 'gestionar productos'],
            'Categorías' => ['ver categorias', 'gestionar categorias'],
            'Ventas' => ['ver ventas', 'realizar ventas', 'cancelar ventas'],
            'Caja' => ['ver cortes', 'gestionar caja'],
            'Clientes' => ['ver clientes', 'gestionar clientes'],
            'Abonos' => ['ver abonos', 'gestionar abonos'],
            'Reportes' => ['ver reportes'],
            'Usuarios' => ['ver usuarios', 'gestionar usuarios'],
            'Roles' => ['gestionar roles'],
            'Configuración' => ['gestionar configuracion'],
        ];

        $existentes = Permission::pluck('name')->toArray();
        $nuevos = [];

        foreach ($grupos as $grupo => $permisos) {
            foreach ($permisos as $perm) {
                if (!in_array($perm, $existentes)) {
                    Permission::create(['name' => $perm]);
                    $nuevos[] = $perm;
                }
            }
        }

        $permisos = Permission::orderBy('name')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
        ]);

        return response()->json([
            'grupos' => $grupos,
            'permisos' => $permisos,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role = Role::create(['name' => $data['name']]);

        if (!empty($data['permissions'])) {
            $role->givePermissionTo($data['permissions']);
        }

        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'label' => ucfirst($role->name),
            'permissions' => $role->permissions->pluck('name'),
            'users_count' => 0,
            'created_at' => $role->created_at->format('Y-m-d H:i'),
        ], 201);
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:roles,name,' . $role->id],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $oldName = $role->name;
        $role->update(['name' => $data['name']]);

        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'label' => ucfirst($role->name),
            'permissions' => $role->permissions->pluck('name'),
            'users_count' => DB::table('model_has_roles')->where('role_id', $role->id)->count(),
            'created_at' => $role->created_at->format('Y-m-d H:i'),
        ]);
    }

    public function destroy(Role $role)
    {
        if ($role->name === 'admin') {
            return response()->json(['message' => 'No se puede eliminar el rol admin'], 422);
        }

        if (DB::table('model_has_roles')->where('role_id', $role->id)->count() > 0) {
            return response()->json(['message' => 'El rol tiene usuarios asignados'], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Rol eliminado']);
    }
}
