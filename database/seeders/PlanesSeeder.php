<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanesSeeder extends Seeder
{
    public function run(): void
    {
        // Limpiar planes existentes (sin empresa asignada se pueden borrar seguros)
        Plan::query()->delete();

        $planes = [
            [
                'nombre'         => 'Gratis',
                'descripcion'    => 'Para empezar. Solo el punto de venta.',
                'precio_mensual' => 0,
                'max_sucursales' => 1,
                'max_usuarios'   => 1,
                'color'          => 'green',
                'activo'         => true,
                'modulos'        => [
                    'dashboard', 'pos', 'ventas', 'caja', 'cortes',
                    'productos', 'categorias', 'clientes',
                ],
            ],
            [
                'nombre'         => 'Básico',
                'descripcion'    => 'POS completo + CFDI + reportes.',
                'precio_mensual' => 499,
                'max_sucursales' => 1,
                'max_usuarios'   => 3,
                'color'          => 'blue',
                'activo'         => true,
                'modulos'        => [
                    'dashboard', 'pos', 'ventas', 'caja', 'cortes',
                    'productos', 'categorias', 'clientes', 'abonos',
                    'cotizaciones', 'pedidos', 'reportes',
                    'proveedores', 'pagos_proveedores',
                    'usuarios', 'roles', 'configuracion', 'facturacion',
                ],
            ],
            [
                'nombre'         => 'Pro',
                'descripcion'    => 'Multi-sucursal + Mercado Libre + todo incluido.',
                'precio_mensual' => 999,
                'max_sucursales' => 3,
                'max_usuarios'   => 10,
                'color'          => 'purple',
                'activo'         => true,
                'modulos'        => [
                    'dashboard', 'pos', 'ventas', 'caja', 'cortes',
                    'productos', 'categorias', 'clientes', 'abonos',
                    'cotizaciones', 'pedidos', 'reportes',
                    'proveedores', 'pagos_proveedores',
                    'usuarios', 'roles', 'sucursales', 'configuracion',
                    'mercado_libre', 'facturacion',
                ],
            ],
            [
                'nombre'         => 'Ilimitado',
                'descripcion'    => 'Sin límites. Todo incluido. Soporte prioritario.',
                'precio_mensual' => 1999,
                'max_sucursales' => -1,
                'max_usuarios'   => -1,
                'color'          => 'amber',
                'activo'         => true,
                'modulos'        => [
                    'dashboard', 'pos', 'ventas', 'caja', 'cortes',
                    'productos', 'categorias', 'clientes', 'abonos',
                    'cotizaciones', 'pedidos', 'reportes',
                    'proveedores', 'pagos_proveedores',
                    'usuarios', 'roles', 'sucursales', 'configuracion',
                    'mercado_libre', 'facturacion',
                ],
            ],
        ];

        foreach ($planes as $plan) {
            Plan::create($plan);
        }

        $this->command->info('✓ 4 planes creados: Gratis, Básico, Pro, Ilimitado');
    }
}
