<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RegisterController extends Controller
{
    // Módulos que se habilitan en trial sin plan seleccionado
    private const MODULOS_TRIAL = [
        'dashboard', 'pos', 'ventas', 'caja', 'cortes',
        'productos', 'categorias', 'clientes', 'abonos',
        'reportes', 'proveedores', 'pagos_proveedores',
        'cotizaciones', 'pedidos', 'usuarios', 'roles',
        'sucursales', 'configuracion', 'camaras', 'ubicaciones',
    ];

    public function register(Request $request)
    {
        $data = $request->validate([
            'empresa_nombre' => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'       => ['required', 'string', 'min:8', 'confirmed'],
            'plan_id'        => ['nullable', 'integer', 'exists:planes,id'],
        ]);

        $plan = isset($data['plan_id']) ? Plan::find($data['plan_id']) : null;

        // Crear empresa
        $empresa = Empresa::create([
            'nombre'             => $data['empresa_nombre'],
            'slug'               => Empresa::generarSlug($data['empresa_nombre']),
            'email'              => $data['email'],
            'plan_id'            => $plan?->id,
            'plan_estado'        => 'trial',
            'plan_vigente_hasta' => now()->addDays(14),
        ]);

        // Sincronizar módulos del plan seleccionado (o los módulos de trial por defecto)
        $modulosActivos = $plan ? ($plan->modulos ?? self::MODULOS_TRIAL) : self::MODULOS_TRIAL;
        foreach ($modulosActivos as $key) {
            $empresa->modulos()->create(['modulo_key' => $key, 'activo' => true]);
        }

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
        $allPerms = Permission::all();
        // Solo sincronizar si ya existen permisos (seeder corrido).
        // Si no hay permisos, no llamar syncPermissions([]) — eso dejaría el rol vacío.
        // El bypass de admin en Sidebar/RequirePermission del frontend cubre este caso.
        if ($allPerms->isNotEmpty()) {
            $adminRole->syncPermissions($allPerms);
        }

        // Crear sucursal principal de la empresa
        $sucursal = Sucursal::withoutGlobalScopes()->create([
            'empresa_id'   => $empresa->id,
            'nombre'       => 'Sucursal Principal',
            'es_principal' => true,
            'activo'       => true,
            'agent_token'  => \Illuminate\Support\Str::random(48),
        ]);

        // Crear usuario admin ligado a la empresa y sucursal principal
        $user = User::create([
            'name'       => $data['empresa_nombre'],
            'email'      => $data['email'],
            'password'   => $data['password'],
            'empresa_id' => $empresa->id,
            'sucursal_id'=> $sucursal->id,
        ]);
        $user->assignRole($adminRole);

        // Registrar usuario en sucursal principal con rol admin
        DB::table('usuario_sucursal')->insert([
            'user_id'     => $user->id,
            'sucursal_id' => $sucursal->id,
            'role_id'     => $adminRole->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Establecer tenant para esta request
        app()->instance('tenant_id', $empresa->id);

        $token = $user->createToken('ventas-pos')->plainTextToken;

        // Enviar email de bienvenida (en background para no bloquear el response)
        try {
            Mail::to($user->email)->queue(new WelcomeMail($user, $empresa->nombre, 14, $modulosActivos));
        } catch (\Throwable) {
            // Silenciar si falla el mail — no romper el registro
        }

        return response()->json([
            'token' => $token,
            'user'  => array_merge($user->fresh()->toArray(), [
                'roles'               => $user->getRoleNames(),
                'permissions'         => $user->getAllPermissions()->pluck('name'),
                'empresa'             => $empresa->only(['id', 'nombre', 'slug']),
                'sucursal_activa'     => $sucursal->only(['id', 'nombre', 'es_principal']),
                'sucursales'          => [array_merge($sucursal->only(['id', 'nombre', 'es_principal']), ['rol' => 'admin'])],
                'modulos_habilitados' => collect($modulosActivos),
            ]),
        ], 201);
    }
}
