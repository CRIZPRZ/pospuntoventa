<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planes', function (Blueprint $table) {
            // mensual | unico — solo aplica a planes tipo 'manual'; los 'stripe' son siempre recurrentes.
            $table->string('periodicidad')->default('mensual')->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('planes', function (Blueprint $table) {
            $table->dropColumn('periodicidad');
        });
    }
};
