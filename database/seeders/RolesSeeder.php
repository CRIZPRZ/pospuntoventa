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
        $permissions = [
            'ver dashboard',
            'gestionar productos', 'ver productos',
            'gestionar categorias', 'ver categorias',
            'realizar ventas', 'ver ventas', 'cancelar ventas',
            'gestionar caja', 'ver cortes',
            'ver reportes',
            'gestionar usuarios', 'ver usuarios',
            'gestionar roles',
        ];

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission]);
        }

        $admin = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions($permissions);

        $cajero = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'cajero']);
        $cajero->syncPermissions([
            'ver dashboard', 'ver productos', 'realizar ventas', 'ver ventas',
            'gestionar caja', 'ver cortes',
        ]);

        $supervisor = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'supervisor']);
        $supervisor->syncPermissions([
            'ver dashboard', 'ver productos', 'gestionar productos',
            'ver categorias', 'gestionar categorias',
            'realizar ventas', 'ver ventas', 'cancelar ventas',
            'gestionar caja', 'ver cortes', 'ver reportes', 'ver usuarios',
        ]);
    }
}
