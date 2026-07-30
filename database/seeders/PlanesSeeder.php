<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Empresa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanesSeeder extends Seeder
{
    public function run(): void
    {
        $planes = [
            [
                'nombre'             => 'Básico',
                'descripcion'        => 'POS completo + 50 timbres CFDI/mes + reportes.',
                'precio_mensual'     => 499,
                'max_sucursales'     => 1,
                'max_usuarios'       => 3,
                'timbres_incluidos'  => 50,
                'color'              => 'blue',
                'tipo'               => 'stripe',
                'activo'             => true,
                'modulos'            => [
                    'dashboard', 'pos', 'ventas', 'caja', 'cortes',
                    'productos', 'categorias', 'clientes', 'abonos',
                    'cotizaciones', 'pedidos', 'reportes',
                    'proveedores', 'pagos_proveedores',
                    'usuarios', 'roles', 'configuracion', 'facturacion',
                ],
            ],
            [
                'nombre'             => 'Pro',
                'descripcion'        => 'Multi-sucursal + Mercado Libre + 200 timbres CFDI/mes.',
                'precio_mensual'     => 999,
                'max_sucursales'     => 3,
                'max_usuarios'       => 10,
                'timbres_incluidos'  => 200,
                'color'              => 'purple',
                'tipo'               => 'stripe',
                'activo'             => true,
                'modulos'            => [
                    'dashboard', 'pos', 'ventas', 'caja', 'cortes',
                    'productos', 'categorias', 'clientes', 'abonos',
                    'cotizaciones', 'pedidos', 'reportes',
                    'proveedores', 'pagos_proveedores',
                    'usuarios', 'roles', 'sucursales', 'configuracion',
                    'mercado_libre', 'facturacion', 'whatsapp',
                ],
            ],
            [
                'nombre'             => 'Ilimitado',
                'descripcion'        => 'Sin límites. Timbres CFDI ilimitados. Soporte prioritario.',
                'precio_mensual'     => 1999,
                'max_sucursales'     => -1,
                'max_usuarios'       => -1,
                'timbres_incluidos'  => -1,
                'color'              => 'amber',
                'tipo'               => 'stripe',
                'activo'             => true,
                'modulos'            => [
                    'dashboard', 'pos', 'ventas', 'caja', 'cortes',
                    'productos', 'categorias', 'clientes', 'abonos',
                    'cotizaciones', 'pedidos', 'reportes',
                    'proveedores', 'pagos_proveedores',
                    'usuarios', 'roles', 'sucursales', 'configuracion',
                    'mercado_libre', 'facturacion', 'whatsapp',
                ],
            ],
        ];

        // updateOrCreate por nombre — no borra planes manuales ni los ya asignados a empresas
        foreach ($planes as $plan) {
            Plan::updateOrCreate(
                ['nombre' => $plan['nombre']],
                $plan
            );
        }

        // Cámaras permanece fuera de todos los planes y trials hasta su lanzamiento.
        DB::table('empresa_modulos')
            ->where('modulo_key', 'camaras')
            ->update(['activo' => false, 'updated_at' => now()]);

        // Aplicar la definición vigente a tenants con planes comerciales activos.
        Empresa::query()
            ->with('plan')
            ->where('plan_estado', 'activo')
            ->whereNotNull('plan_id')
            ->chunkById(100, function ($empresas) {
                foreach ($empresas as $empresa) {
                    if (!$empresa->plan || $empresa->plan->tipo === 'manual') {
                        continue;
                    }

                    $empresa->modulos()->update(['activo' => false]);
                    foreach ($empresa->plan->modulos ?? [] as $key) {
                        $empresa->modulos()->updateOrCreate(
                            ['modulo_key' => $key],
                            ['activo' => true],
                        );
                    }
                }
            });

        $this->command->info('✓ Planes sincronizados: Básico, Pro, Ilimitado');
    }
}
