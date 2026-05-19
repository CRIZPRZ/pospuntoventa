<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'c.lira.prz@gmail.com';

        $user = User::withoutGlobalScopes()->updateOrCreate(
            ['email' => $email],
            [
                'name'          => 'Cristian Lira',
                'password'      => '01Dejunio',
                'is_superadmin' => true,
                'empresa_id'    => null,
                'sucursal_id'   => null,
            ]
        );

        $this->command->info("SuperAdmin listo: {$user->email}");
    }
}
