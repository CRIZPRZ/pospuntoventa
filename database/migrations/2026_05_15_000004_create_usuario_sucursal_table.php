<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla pivot: usuario ↔ sucursal + rol en esa sucursal
        Schema::create('usuario_sucursal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'sucursal_id']);
        });

        // Migrar asignaciones existentes: copiar rol actual del usuario
        // a su sucursal_id (la principal que se creó en migration anterior)
        $assignments = DB::table('model_has_roles')
            ->where('model_type', 'App\\Models\\User')
            ->get();

        foreach ($assignments as $a) {
            $user = DB::table('users')->find($a->model_id);
            if (!$user || !$user->sucursal_id) {
                continue;
            }

            // Evitar duplicado si ya existe
            $exists = DB::table('usuario_sucursal')
                ->where('user_id', $user->id)
                ->where('sucursal_id', $user->sucursal_id)
                ->exists();

            if (!$exists) {
                DB::table('usuario_sucursal')->insert([
                    'user_id'     => $user->id,
                    'sucursal_id' => $user->sucursal_id,
                    'role_id'     => $a->role_id,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_sucursal');
    }
};
