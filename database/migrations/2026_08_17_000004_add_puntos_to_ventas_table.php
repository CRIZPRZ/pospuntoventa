<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->unsignedInteger('puntos_ganados')->default(0)->after('total');
            $table->unsignedInteger('puntos_canjeados')->default(0)->after('puntos_ganados');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['puntos_ganados', 'puntos_canjeados']);
        });
    }
};
