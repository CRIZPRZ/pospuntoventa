<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('codigo_postal', 10)->nullable()->after('rfc');
            $table->string('regimen_fiscal', 4)->nullable()->after('codigo_postal');
            $table->string('uso_cfdi', 4)->nullable()->after('regimen_fiscal');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['codigo_postal', 'regimen_fiscal', 'uso_cfdi']);
        });
    }
};
