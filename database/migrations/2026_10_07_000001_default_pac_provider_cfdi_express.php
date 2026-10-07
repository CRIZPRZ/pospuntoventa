<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('pac_provider', 20)->default('cfdi_express')->change();
        });

        // Todos los tenants pasan a CFDI Express. Deben volver a activar
        // facturación (subir CSD) porque el emisor vive en otro PAC.
        DB::table('empresas')->update(['pac_provider' => 'cfdi_express']);

        // Emisor EventPOS (facturación de suscripciones): mismo PAC. El CSD
        // subido al PAC anterior no existe en CFDI Express → forzar re-subida.
        DB::table('superadmin_config')->updateOrInsert(
            ['key' => 'fiscal_pac_provider'],
            ['value' => 'cfdi_express', 'updated_at' => now()]
        );
        DB::table('superadmin_config')
            ->where('key', 'fiscal_csd_subido')
            ->update(['value' => 'false', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('pac_provider', 20)->default('facturama')->change();
        });
    }
};
