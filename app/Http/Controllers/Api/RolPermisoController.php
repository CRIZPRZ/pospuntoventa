<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolPermisoController extends Controller
{
    private function tenantId(): int
    {
        return app('tenant_id');
    }

    public function index()
    {
        $tenantId = $this->tenantId();

        $usersCount = DB::table('model_has_roles')
            ->select('role_id', DB::raw('count(*) as count'))
            ->groupBy('role_id')
            ->pluck('count', 'role_id');

        $roles = Role::where('empresa_id', $tenantId)
            ->with('permissions')
            ->latest()
            ->get()
            ->map(function ($role) use ($usersCount) {
                return [
                    'id'          => $role->id,
                    'name'        => $role->name,
                    'label'       => ucfirst($role->name),
                    'permissions' => $role->permissions->pluck('name'),
                    'users_count' => $usersCount->get($role->id, 0),
                    'es_admin'    => $role->name === 'admin',
                    'created_at'  => $role->created_at->format('Y-m-d H:i'),
                ];
            });

        return response()->json($roles);
    }

    public function permisos()
    {
        $grupos = [
            'Dashboard'         => ['ver dashboard'],
            'Productos'         => ['ver productos', 'gestionar productos'],
            'Categorías'        => ['ver categorias', 'gestionar categorias'],
            'Ventas'            => ['ver ventas', 'realizar ventas', 'cancelar ventas'],
            'Caja'              => ['ver cortes', 'gestionar caja'],
            'Cortes'            => ['ver cortes', 'gestionar cortes'],
            'Clientes'          => ['ver clientes', 'gestionar clientes'],
            'Abonos'            => ['ver abonos', 'gestionar abonos'],
            'Reportes'          => ['ver reportes'],
            'Usuarios'          => ['ver usuarios', 'gestionar usuarios'],
            'Roles'             => ['gestionar roles'],
            'Configuración'     => ['gestionar configuracion'],
            'Proveedores'       => ['ver proveedores', 'gestionar proveedores'],
            'Pagos Proveedores' => ['ver pagos proveedores', 'gestionar pagos proveedores'],
            'Cotizaciones'      => ['ver cotizaciones', 'gestionar cotizaciones'],
            'Sucursales'        => ['ver sucursales', 'gestionar sucursales'],
        ];

        $permisos = Permission::orderBy('name')->get()->map(fn ($p) => [
            'id'   => $p->id,
            'name' => $p->name,
        ]);

        return response()->json([
            'grupos'   => $grupos,
            'permisos' => $permisos,
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId();

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:50'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $exists = Role::where('empresa_id', $tenantId)->where('name', $data['name'])->exists();
        if ($exists) {
            return response()->json(['errors' => ['name' => ['El nombre ya existe']]], 422);
        }

        // Insert directo: Spatie valida unicidad por (name, guard_name) sin empresa_id
        $roleId = DB::table('roles')->insertGetId([
            'name'       => $data['name'],
            'guard_name' => 'web',
            'empresa_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $role = Role::find($roleId);

        if (!empty($data['permissions'])) {
            $role->givePermissionTo($data['permissions']);
        }

        return response()->json([
            'id'          => $role->id,
            'name'        => $role->name,
            'label'       => ucfirst($role->name),
            'permissions' => $role->permissions->pluck('name'),
            'users_count' => 0,
            'es_admin'    => false,
            'created_at'  => $role->created_at->format('Y-m-d H:i'),
        ], 201);
    }

    public function update(Request $request, Role $role)
    {
        $tenantId = $this->tenantId();

        if ((int) $role->empresa_id !== $tenantId) {
            abort(403);
        }

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:50'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        if ($role->name !== 'admin') {
            $exists = Role::where('empresa_id', $tenantId)
                ->where('name', $data['name'])
                ->where('id', '!=', $role->id)
                ->exists();
            if ($exists) {
                return response()->json(['errors' => ['name' => ['El nombre ya existe']]], 422);
            }
            $role->update(['name' => $data['name']]);
        }

        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        return response()->json([
            'id'          => $role->id,
            'name'        => $role->name,
            'label'       => ucfirst($role->name),
            'permissions' => $role->fresh('permissions')->permissions->pluck('name'),
            'users_count' => DB::table('model_has_roles')->where('role_id', $role->id)->count(),
            'es_admin'    => $role->name === 'admin',
            'created_at'  => $role->created_at->format('Y-m-d H:i'),
        ]);
    }

    public function destroy(Role $role)
    {
        $tenantId = $this->tenantId();

        if ((int) $role->empresa_id !== $tenantId) {
            abort(403);
        }

        if ($role->name === 'admin') {
            return response()->json(['message' => 'El rol admin no puede eliminarse'], 422);
        }

        if (DB::table('model_has_roles')->where('role_id', $role->id)->count() > 0) {
            return response()->json(['message' => 'El rol tiene usuarios asignados'], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Rol eliminado']);
    }
}
