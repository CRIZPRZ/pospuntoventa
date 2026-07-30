<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UsuarioController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->with('roles')->latest();

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->paginate($request->integer('per_page', 50))
                  ->through(fn (User $user) => $this->formatUser($user))
        );
    }

    public function show(User $usuario)
    {
        return response()->json($this->formatUser($usuario->load('roles')));
    }

    public function store(Request $request)
    {
        $tenantId = app('tenant_id');
        $empresa = $request->user()?->empresa?->loadMissing('plan');

        if (! $empresa || $empresa->id !== $tenantId) {
            return response()->json(['message' => 'Empresa no encontrada'], 422);
        }

        $limiteUsuarios = $empresa->limiteUsuarios();
        $usuariosActuales = $empresa->usuarios()->count();

        if ($limiteUsuarios !== -1 && $usuariosActuales >= $limiteUsuarios) {
            return response()->json([
                'message' => "Tu plan permite hasta {$limiteUsuarios} usuario(s). Actualiza tu plan para agregar otro.",
            ], 422);
        }

        // Only show roles belonging to this tenant
        $roles = Role::where('empresa_id', $tenantId)->pluck('name')->toArray();

        $sucursalIds = Sucursal::pluck('id')->toArray();

        $data = $request->validate([
            'nombre'      => ['required_without:name', 'string', 'max:255'],
            'name'        => ['required_without:nombre', 'string', 'max:255'],
            'email'       => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'    => ['required', 'string', 'min:6'],
            'rol'         => ['required', Rule::in($roles)],
            'sucursal_id' => ['nullable', Rule::in($sucursalIds)],
        ]);

        // Default: sucursal principal de la empresa
        $sucursalId = $data['sucursal_id']
            ?? Sucursal::where('es_principal', true)->value('id')
            ?? Sucursal::first()?->id;

        $user = User::create([
            'name'        => $data['name'] ?? $data['nombre'],
            'email'       => $data['email'],
            'password'    => $data['password'],
            'empresa_id'  => $tenantId,
            'sucursal_id' => $sucursalId,
        ]);

        $role = Role::where('empresa_id', $tenantId)->where('name', $data['rol'])->first();
        $user->assignRole($role);

        if ($sucursalId) {
            DB::table('usuario_sucursal')->updateOrInsert(
                ['user_id' => $user->id, 'sucursal_id' => $sucursalId],
                ['role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        return response()->json($this->formatUser($user->load('roles')), 201);
    }

    public function update(Request $request, User $usuario)
    {
        $tenantId = app('tenant_id');
        $roles    = Role::where('empresa_id', $tenantId)->pluck('name')->toArray();

        $sucursalIds = Sucursal::pluck('id')->toArray();

        $data = $request->validate([
            'nombre'      => ['required_without:name', 'string', 'max:255'],
            'name'        => ['required_without:nombre', 'string', 'max:255'],
            'email'       => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario)],
            'password'    => ['nullable', 'string', 'min:6'],
            'rol'         => ['required', Rule::in($roles)],
            'sucursal_id' => ['nullable', Rule::in($sucursalIds)],
        ]);

        $updateData = array_filter([
            'name'     => $data['name'] ?? $data['nombre'],
            'email'    => $data['email'],
            'password' => $data['password'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (!empty($data['sucursal_id'])) {
            $updateData['sucursal_id'] = $data['sucursal_id'];
        }

        $usuario->update($updateData);

        $role = Role::where('empresa_id', $tenantId)->where('name', $data['rol'])->first();
        $usuario->syncRoles([$role]);

        // Actualizar rol en sucursal activa del usuario
        $sucursalId = $data['sucursal_id'] ?? $usuario->sucursal_id;
        if ($sucursalId && $role) {
            DB::table('usuario_sucursal')->updateOrInsert(
                ['user_id' => $usuario->id, 'sucursal_id' => $sucursalId],
                ['role_id' => $role->id, 'updated_at' => now()]
            );
        }

        return response()->json($this->formatUser($usuario->fresh('roles')));
    }

    public function destroy(User $usuario)
    {
        if (auth()->id() === $usuario->id) {
            return response()->json(['message' => 'No puedes eliminar tu propio usuario'], 422);
        }

        $usuario->delete();

        return response()->json(['message' => 'Usuario eliminado']);
    }

    public function toggle(User $usuario)
    {
        return response()->json([
            'message' => 'El backend actual no maneja estado activo/inactivo de usuarios',
            'user'    => $this->formatUser($usuario->load('roles')),
        ]);
    }

    private function formatUser(User $user): array
    {
        $sucursales = DB::table('usuario_sucursal as us')
            ->join('sucursales', 'sucursales.id', '=', 'us.sucursal_id')
            ->join('roles', 'roles.id', '=', 'us.role_id')
            ->where('us.user_id', $user->id)
            ->select('sucursales.id', 'sucursales.nombre', 'roles.name as rol')
            ->get();

        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'rol'          => $user->roles->first()?->name ?? 'sin rol',
            'roles'        => $user->roles->pluck('name')->values(),
            'sucursal_id'  => $user->sucursal_id,
            'sucursales'   => $sucursales,
            'activo'       => true,
            'created_at'   => $user->created_at,
            'updated_at'   => $user->updated_at,
        ];
    }
}
