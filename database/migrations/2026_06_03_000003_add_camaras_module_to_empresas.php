<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $empresaIds = DB::table('empresas')->pluck('id');

        foreach ($empresaIds as $empresaId) {
            $exists = DB::table('empresa_modulos')
                ->where('empresa_id', $empresaId)
                ->where('modulo_key', 'camaras')
                ->exists();

            if (!$exists) {
                DB::table('empresa_modulos')->insert([
                    'empresa_id'  => $empresaId,
                    'modulo_key'  => 'camaras',
                    'activo'      => true,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('empresa_modulos')->where('modulo_key', 'camaras')->delete();
    }
};
