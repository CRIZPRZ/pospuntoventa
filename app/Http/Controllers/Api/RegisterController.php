<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RegisterController extends Controller
{
    // Módulos que se habilitan durante el trial.
    private const MODULOS_TRIAL = [
        'dashboard', 'pos', 'ventas', 'caja', 'cortes',
        'productos', 'categorias', 'clientes', 'abonos',
        'reportes', 'proveedores', 'pagos_proveedores',
        'cotizaciones', 'pedidos', 'usuarios', 'roles',
        'configuracion',
    ];

    public function register(Request $request)
    {
        $data = $request->validate([
            'empresa_nombre' => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'       => ['required', 'string', 'min:8', 'confirmed'],
            'website'        => ['nullable', 'max:0'],
            'flow_started_at'=> ['nullable', 'integer'],
        ], [
            'empresa_nombre.required' => 'Escribe el nombre de tu empresa.',
            'empresa_nombre.string'   => 'El nombre de la empresa no es válido.',
            'empresa_nombre.max'      => 'El nombre de la empresa no puede exceder 255 caracteres.',
            'email.required'          => 'Escribe tu correo electrónico.',
            'email.email'             => 'Ingresa un correo electrónico válido.',
            'email.max'               => 'El correo electrónico no puede exceder 255 caracteres.',
            'email.unique'            => 'Este correo ya está registrado.',
            'password.required'       => 'Escribe una contraseña.',
            'password.string'         => 'La contraseña no es válida.',
            'password.min'            => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed'      => 'Las contraseñas no coinciden.',
            'website.max'             => 'No se pudo procesar el registro.',
            'flow_started_at.integer' => 'No se pudo procesar el registro.',
        ]);

        if (! empty($data['website'])) {
            Log::warning('Registro bloqueado por honeypot', ['ip' => $request->ip(), 'email' => $data['email'] ?? null]);
            return response()->json(['message' => 'No se pudo procesar el registro.'], 422);
        }

        if (! empty($data['flow_started_at'])) {
            $startedAt = Carbon::createFromTimestampMs((int) $data['flow_started_at']);

            if ($startedAt->greaterThan(now()->subSeconds(2))) {
                Log::warning('Registro bloqueado por tiempo mínimo del formulario', [
                    'ip' => $request->ip(),
                    'email' => $data['email'] ?? null,
                ]);
                return response()->json(['message' => 'Confirma tus datos y vuelve a intentarlo.'], 422);
            }
        }

        // Crear empresa
        $empresa = Empresa::create([
            'nombre'             => $data['empresa_nombre'],
            'slug'               => Empresa::generarSlug($data['empresa_nombre']),
            'email'              => $data['email'],
            'plan_estado'        => 'trial',
            'plan_vigente_hasta' => now()->addDays(14),
        ]);

        // El registro siempre inicia en trial; la contratación ocurre después.
        $modulosActivos = self::MODULOS_TRIAL;
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
