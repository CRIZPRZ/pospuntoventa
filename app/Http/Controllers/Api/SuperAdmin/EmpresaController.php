<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EmpresaModulo;
use App\Models\Plan;
use App\Models\RecargaCredito;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\DesktopLicenseService;
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

        $timbresUsadosMes = \App\Models\TimbreConsumo::where('empresa_id', $empresa->id)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        $data = $empresa->toArray();
        $data['timbres_usados_mes'] = $timbresUsadosMes;

        return response()->json(['data' => $data]);
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
                'nombre'             => $data['nombre'],
                'slug'               => Empresa::generarSlug($data['nombre']),
                'email'              => $data['email'],
                'status'             => 'activa',
                'plan_estado'        => 'sin_plan',
                'plan_vigente_hasta' => now(),
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
            'nombre'       => 'sometimes|string|max:255',
            'email'        => 'sometimes|email|unique:empresas,email,' . $empresa->id,
            'status'       => 'sometimes|in:activa,suspendida',
            'pac_provider' => 'sometimes|in:facturapi,facturama,sw_sapiens',
            'whatsapp_provider' => 'sometimes|in:cloud_api,baileys,disabled',
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

    public function recargarTimbres(Request $request, Empresa $empresa)
    {
        $data = $request->validate([
            'cantidad'    => 'required|integer|min:1|max:10000',
            'nota'        => 'nullable|string|max:255',
        ]);

        $empresa->increment('timbres_extra', (int) $data['cantidad']);

        return response()->json([
            'data'    => ['timbres_extra' => $empresa->fresh()->timbres_extra],
            'message' => "{$data['cantidad']} timbres agregados correctamente",
        ]);
    }

    public function recargarCredito(Request $request, Empresa $empresa)
    {
        $data = $request->validate([
            'pesos'       => 'required|numeric|min:0|max:100000',
            'costo_timbre'=> 'nullable|numeric|min:0.01|max:1000',
        ]);

        $pesos = (float) $data['pesos'];

        if ($pesos > 0) {
            $empresa->increment('credito_timbres', $pesos);

            RecargaCredito::create([
                'empresa_id'   => $empresa->id,
                'pesos'        => $pesos,
                'costo_timbre' => isset($data['costo_timbre']) ? (float) $data['costo_timbre'] : $empresa->costo_timbre,
            ]);
        }

        if (isset($data['costo_timbre'])) {
            $empresa->update(['costo_timbre' => (float) $data['costo_timbre']]);
        }

        $fresh = $empresa->fresh();
        $msg = $pesos > 0
            ? '$' . number_format($pesos, 2) . ' MXN agregados al crédito'
            : 'Costo por timbre actualizado';
        return response()->json([
            'data'    => [
                'credito_timbres' => (float) $fresh->credito_timbres,
                'costo_timbre'    => (float) $fresh->costo_timbre,
            ],
            'message' => $msg,
        ]);
    }

    public function license(Empresa $empresa, DesktopLicenseService $service)
    {
        $license = $service->getOrCreateForEmpresa($empresa);

        return response()->json([
            'data' => array_merge(
                $service->buildResponse($license),
                [
                    'devices' => $license->devices()
                        ->orderByDesc('is_active')
                        ->orderByDesc('last_seen_at')
                        ->get([
                            'device_uuid',
                            'device_name',
                            'platform',
                            'app_version',
                            'last_seen_at',
                            'last_token_issued_at',
                            'revoked_at',
                            'is_active',
                        ]),
                ]
            ),
        ]);
    }

    public function updateLicense(Request $request, Empresa $empresa, DesktopLicenseService $service)
    {
        $data = $request->validate([
            'status' => 'required|in:active,suspended,cancelled',
            'max_devices' => 'required|integer|min:1|max:100',
        ]);

        $license = $service->getOrCreateForEmpresa($empresa);
        $license->update([
            'status' => $data['status'],
            'max_devices' => (int) $data['max_devices'],
        ]);

        return response()->json([
            'message' => 'Licencia actualizada correctamente.',
            'data' => array_merge(
                $service->buildResponse($license->fresh()),
                [
                    'devices' => $license->fresh()->devices()
                        ->orderByDesc('is_active')
                        ->orderByDesc('last_seen_at')
                        ->get([
                            'device_uuid',
                            'device_name',
                            'platform',
                            'app_version',
                            'last_seen_at',
                            'last_token_issued_at',
                            'revoked_at',
                            'is_active',
                        ]),
                ]
            ),
        ]);
    }

    public function revokeLicenseDevice(Empresa $empresa, string $deviceUuid, DesktopLicenseService $service)
    {
        $license = $service->getOrCreateForEmpresa($empresa);

        $device = $license->devices()
            ->where('device_uuid', $deviceUuid)
            ->firstOrFail();

        $service->deactivateDevice($device);

        return response()->json([
            'message' => 'Dispositivo revocado correctamente.',
            'data' => array_merge(
                $service->buildResponse($license->fresh()),
                [
                    'devices' => $license->fresh()->devices()
                        ->orderByDesc('is_active')
                        ->orderByDesc('last_seen_at')
                        ->get([
                            'device_uuid',
                            'device_name',
                            'platform',
                            'app_version',
                            'last_seen_at',
                            'last_token_issued_at',
                            'revoked_at',
                            'is_active',
                        ]),
                ]
            ),
        ]);
    }

    private function modulosDefault(): array
    {
        return [
            'dashboard', 'pos', 'ventas', 'caja', 'cortes',
            'productos', 'categorias', 'clientes', 'abonos',
            'reportes', 'proveedores', 'pagos_proveedores',
            'cotizaciones', 'pedidos', 'usuarios', 'roles',
            'configuracion',
        ];
    }
}
