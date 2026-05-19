<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';
        foreach (['ver facturacion', 'gestionar facturacion'] as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => $guard]
            );
        }

        // Grant to admin role of every tenant
        $adminRoles = \Spatie\Permission\Models\Role::where('name', 'admin')->get();
        foreach ($adminRoles as $role) {
            $role->givePermissionTo(['ver facturacion', 'gestionar facturacion']);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['ver facturacion', 'gestionar facturacion'] as $name) {
            \Spatie\Permission\Models\Permission::where('name', $name)->delete();
        }
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
