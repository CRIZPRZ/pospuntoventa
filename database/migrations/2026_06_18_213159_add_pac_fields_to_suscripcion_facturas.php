<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('suscripcion_facturas', function (Blueprint $table) {
            $table->string('cfdi_pac_id')->nullable()->after('cfdi_facturapi_id');
            $table->string('cfdi_pac')->nullable()->after('cfdi_pac_id');
        });
    }

    public function down(): void
    {
        Schema::table('suscripcion_facturas', function (Blueprint $table) {
            $table->dropColumn(['cfdi_pac_id', 'cfdi_pac']);
        });
    }
};
