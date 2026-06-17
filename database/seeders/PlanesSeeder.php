<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanesSeeder extends Seeder
{
    public function run(): void
    {
        $planes = [
            [
                'nombre'             => 'Gratis',
                'descripcion'        => 'Para empezar. Solo el punto de venta.',
                'precio_mensual'     => 0,
                'max_sucursales'     => 1,
                'max_usuarios'       => 1,
                'timbres_incluidos'  => 0,
                'color'              => 'green',
                'tipo'               => 'gratis',
                'activo'             => true,
                'modulos'            => [
                    'dashboard', 'pos', 'ventas', 'caja', 'cortes',
                    'productos', 'categorias', 'clientes', 'ubicaciones',
                ],
            ],
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
                    'mercado_libre', 'facturacion',
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
                    'mercado_libre', 'facturacion',
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

        $this->command->info('✓ Planes sincronizados: Gratis, Básico, Pro, Ilimitado');
    }
}
