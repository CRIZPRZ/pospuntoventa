<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea el superadmin con credenciales del .env (SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD).
 * Nunca guardar la contraseña en el código. Si el usuario ya existe, no se toca su
 * contraseña a menos que SUPERADMIN_PASSWORD esté definida.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('SUPERADMIN_EMAIL', 'c.lira.prz@gmail.com');
        $password = env('SUPERADMIN_PASSWORD');
        $user     = User::withoutGlobalScopes()->where('email', $email)->first();

        if (!$user && !$password) {
            $this->command->warn('SuperAdminSeeder: define SUPERADMIN_PASSWORD en .env para crear el superadmin. Omitido.');
            return;
        }

        $attributes = [
            'name'          => $user?->name ?? 'Cristian Lira',
            'is_superadmin' => true,
            'empresa_id'    => null,
            'sucursal_id'   => null,
        ];
        if ($password) {
            $attributes['password'] = $password;
        }

        $user = User::withoutGlobalScopes()->updateOrCreate(['email' => $email], $attributes);

        $this->command->info("SuperAdmin listo: {$user->email}");
    }
}
