<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sucursales', function (Blueprint $table) {
            $table->string('mediamtx_url')->nullable()->after('modo_caja')
                ->comment('URL base de MediaMTX local, ej: http://192.168.1.50:8888');
        });
    }

    public function down(): void
    {
        Schema::table('sucursales', function (Blueprint $table) {
            $table->dropColumn('mediamtx_url');
        });
    }
};
