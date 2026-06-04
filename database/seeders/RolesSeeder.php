<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $guard = 'web';

        $permissions = [
            'ver dashboard',
            'gestionar productos', 'ver productos',
            'gestionar categorias', 'ver categorias',
            'realizar ventas', 'ver ventas', 'cancelar ventas',
            'gestionar caja', 'ver cortes', 'gestionar cortes',
            'ver reportes',
            'gestionar usuarios', 'ver usuarios',
            'gestionar roles',
            'ver clientes', 'gestionar clientes',
            'ver abonos', 'gestionar abonos',
            'ver proveedores', 'gestionar proveedores',
            'ver pagos proveedores', 'gestionar pagos proveedores',
            'ver cotizaciones', 'gestionar cotizaciones',
            'ver pedidos', 'gestionar pedidos',
            'gestionar configuracion',
            'ver sucursales', 'gestionar sucursales',
            'ver mercado libre', 'gestionar mercado libre',
            'ver facturacion', 'gestionar facturacion',
            'ver camaras', 'gestionar camaras',
        ];

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => $guard]
            );
        }

        // Roles are created per-tenant during registration — no roles seeded here
    }
}
