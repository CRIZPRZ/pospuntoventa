<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RegisterController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'empresa_nombre' => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'       => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Crear empresa
        $empresa = Empresa::create([
            'nombre' => $data['empresa_nombre'],
            'slug'   => Empresa::generarSlug($data['empresa_nombre']),
            'email'  => $data['email'],
        ]);

        // Crear rol admin para esta empresa con todos los permisos globales.
        // Se inserta directo a DB porque Spatie valida unicidad por (name, guard_name)
        // sin considerar empresa_id — la constraint real en DB sí lo permite.
        $roleId = DB::table('roles')->insertGetId([
            'name'       => 'admin',
            'guard_name' => 'web',
            'empresa_id' => $empresa->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $adminRole = Role::find($roleId);
        $adminRole->syncPermissions(Permission::all());

        // Crear usuario admin ligado a la empresa
        $user = User::create([
            'name'       => $data['empresa_nombre'],
            'email'      => $data['email'],
            'password'   => $data['password'],
            'empresa_id' => $empresa->id,
        ]);
        $user->assignRole($adminRole);

        // Establecer tenant para esta request
        app()->instance('tenant_id', $empresa->id);

        $token = $user->createToken('ventas-pos')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => array_merge($user->fresh()->toArray(), [
                'roles'       => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'empresa'     => $empresa->only(['id', 'nombre', 'slug']),
            ]),
        ], 201);
    }
}
