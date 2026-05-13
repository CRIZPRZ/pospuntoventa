<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('cfdi_uuid', 40)->nullable()->after('notas');
            $table->longText('cfdi_xml')->nullable()->after('cfdi_uuid');
            $table->enum('cfdi_status', ['timbrado', 'cancelado'])->nullable()->after('cfdi_xml');
            $table->json('cfdi_receptor')->nullable()->after('cfdi_status');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['cfdi_uuid', 'cfdi_xml', 'cfdi_status', 'cfdi_receptor']);
        });
    }
};
