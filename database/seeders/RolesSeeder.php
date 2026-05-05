<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
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
            'gestionar configuracion',
        ];

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => $guard]
            );
        }

        $admin = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => $guard]
        );
        $admin->syncPermissions($permissions);

        $cajero = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'cajero', 'guard_name' => $guard]
        );
        $cajero->syncPermissions([
            'ver dashboard', 'ver productos', 'realizar ventas', 'ver ventas',
            'gestionar caja', 'ver cortes',
        ]);

        $supervisor = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'supervisor', 'guard_name' => $guard]
        );
        $supervisor->syncPermissions([
            'ver dashboard', 'ver productos', 'gestionar productos',
            'ver categorias', 'gestionar categorias',
            'realizar ventas', 'ver ventas', 'cancelar ventas',
            'gestionar caja', 'ver cortes', 'ver reportes', 'ver usuarios',
            'ver clientes', 'ver abonos', 'ver proveedores', 'ver pagos proveedores',
        ]);
    }
}
