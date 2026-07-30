<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            // ID externo del CFDI en el PAC (antes específico de Facturapi)
            $table->renameColumn('cfdi_facturapi_id', 'cfdi_pac_id');
        });

        Schema::table('ventas', function (Blueprint $table) {
            // PAC que emitió el CFDI — para descarga/cancelación con el PAC correcto
            $table->string('cfdi_pac', 20)->nullable()->after('cfdi_pac_id');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('cfdi_pac');
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->renameColumn('cfdi_pac_id', 'cfdi_facturapi_id');
        });
    }
};
