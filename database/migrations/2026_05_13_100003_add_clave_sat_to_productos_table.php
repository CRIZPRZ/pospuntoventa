<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('clave_sat', 10)->default('01010101')->after('unidad');
            $table->string('clave_unidad_sat', 10)->default('H87')->after('clave_sat');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['clave_sat', 'clave_unidad_sat']);
        });
    }
};
