<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = \App\Models\User::firstOrCreate(
            ['email' => 'admin@ventas.com'],
            [
                'name' => 'Administrador',
                'password' => bcrypt('password'),
            ]
        );
        $admin->assignRole('admin');

        $cajero = \App\Models\User::firstOrCreate(
            ['email' => 'cajero@ventas.com'],
            [
                'name' => 'Cajero Principal',
                'password' => bcrypt('password'),
            ]
        );
        $cajero->assignRole('cajero');
    }
}
