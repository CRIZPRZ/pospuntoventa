<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planes', function (Blueprint $table) {
            // gratis | stripe | manual
            $table->string('tipo')->default('stripe')->after('activo');
        });

        // Actualizar planes existentes
        \DB::table('planes')->where('precio_mensual', 0)->update(['tipo' => 'gratis']);
        \DB::table('planes')->where('precio_mensual', '>', 0)->update(['tipo' => 'stripe']);
    }

    public function down(): void
    {
        Schema::table('planes', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
