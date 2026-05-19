<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EmpresaModulo;
use App\Models\Plan;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class EmpresaController extends Controller
{
    public function index()
    {
        $empresas = Empresa::with('plan:id,nombre,precio_mensual,color')
            ->withCount(['usuarios', 'modulos' => fn($q) => $q->where('activo', true)])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $empresas]);
    }

    public function show(Empresa $empresa)
    {
        $empresa->load('modulos', 'plan');
        $empresa->loadCount('usuarios');

        return response()->json(['data' => $empresa]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'   => 'required|string|max:255',
            'email'    => 'required|email|unique:empresas,email|unique:users,email',
            'password' => 'required|string|min:8',
            'modulos'  => 'nullable|array',
            'modulos.*'=> 'string',
        ]);

        return DB::transaction(function () use ($data) {
            $empresa = Empresa::create([
                'nombre' => $data['nombre'],
                'slug'   => Empresa::generarSlug($data['nombre']),
                'email'  => $data['email'],
                'status' => 'activa',
            ]);

            $roleId = DB::table('roles')->insertGetId([
                'name'       => 'admin',
                'guard_name' => 'web',
                'empresa_id' => $empresa->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $adminRole = Role::find($roleId);
            $adminRole->syncPermissions(Permission::all());

            $sucursal = Sucursal::withoutGlobalScopes()->create([
                'empresa_id'   => $empresa->id,
                'nombre'       => 'Sucursal Principal',
                'es_principal' => true,
                'activo'       => true,
            ]);

            $user = User::withoutGlobalScopes()->create([
                'name'        => $data['nombre'],
                'email'       => $data['email'],
                'password'    => bcrypt($data['password']),
                'empresa_id'  => $empresa->id,
                'sucursal_id' => $sucursal->id,
            ]);
            $user->assignRole($adminRole);

            DB::table('usuario_sucursal')->insert([
                'user_id'     => $user->id,
                'sucursal_id' => $sucursal->id,
                'role_id'     => $adminRole->id,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // Crear módulos habilitados
            $modulos = $data['modulos'] ?? $this->modulosDefault();
            foreach ($modulos as $key) {
                EmpresaModulo::create([
                    'empresa_id'  => $empresa->id,
                    'modulo_key'  => $key,
                    'activo'      => true,
                ]);
            }

            return response()->json(['data' => $empresa->load('modulos')], 201);
        });
    }

    public function update(Request $request, Empresa $empresa)
    {
        $data = $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'email'  => 'sometimes|email|unique:empresas,email,' . $empresa->id,
            'status' => 'sometimes|in:activa,suspendida',
        ]);

        $empresa->update($data);

        return response()->json(['data' => $empresa->fresh()->load('modulos')]);
    }

    public function destroy(Empresa $empresa)
    {
        $empresa->delete();
        return response()->json(['message' => 'Empresa eliminada']);
    }

    public function modulos(Empresa $empresa)
    {
        return response()->json(['data' => $empresa->modulos]);
    }

    public function toggleModulo(Request $request, Empresa $empresa)
    {
        $data = $request->validate([
            'modulo_key' => 'required|string',
            'activo'     => 'required|boolean',
        ]);

        EmpresaModulo::updateOrCreate(
            ['empresa_id' => $empresa->id, 'modulo_key' => $data['modulo_key']],
            ['activo' => $data['activo']]
        );

        return response()->json(['data' => $empresa->fresh()->load('modulos')]);
    }

    public function setModulos(Request $request, Empresa $empresa)
    {
        $data = $request->validate([
            'modulos'   => 'required|array',
            'modulos.*' => 'string',
        ]);

        // Elimina los existentes y recrea
        $empresa->modulos()->delete();
        foreach ($data['modulos'] as $key) {
            EmpresaModulo::create([
                'empresa_id' => $empresa->id,
                'modulo_key' => $key,
                'activo'     => true,
            ]);
        }

        return response()->json(['data' => $empresa->fresh()->load('modulos')]);
    }

    public function asignarPlan(Request $request, Empresa $empresa)
    {
        $data = $request->validate([
            'plan_id'          => 'required|exists:planes,id',
            'vigente_hasta'    => 'nullable|date',
            'precio_pactado'   => 'nullable|numeric|min:0',
        ]);

        $plan = Plan::findOrFail($data['plan_id']);

        DB::transaction(function () use ($empresa, $plan, $data) {
            $empresa->update([
                'plan_id'             => $plan->id,
                'plan_vigente_hasta'  => $data['vigente_hasta'] ?? null,
                'plan_estado'         => 'activo',
                'plan_precio_pactado' => $data['precio_pactado'] ?? null,
            ]);

            // Sincronizar módulos de la empresa con los del plan
            if (! empty($plan->modulos)) {
                // Desactivar todos primero
                $empresa->modulos()->update(['activo' => false]);

                // Activar los del plan
                foreach ($plan->modulos as $key) {
                    $empresa->modulos()->updateOrCreate(
                        ['modulo_key' => $key],
                        ['activo' => true]
                    );
                }
            }
        });

        return response()->json([
            'data' => $empresa->fresh()->load('modulos', 'plan'),
            'message' => 'Plan asignado correctamente',
        ]);
    }

    private function modulosDefault(): array
    {
        return [
            'dashboard', 'pos', 'ventas', 'caja', 'cortes',
            'productos', 'categorias', 'clientes', 'abonos',
            'reportes', 'proveedores', 'pagos_proveedores',
            'cotizaciones', 'pedidos', 'usuarios', 'roles',
            'sucursales', 'configuracion',
        ];
    }
}
