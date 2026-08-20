<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->unsignedInteger('points_balance')->default(0)->after('saldo_credito');
            $table->unsignedInteger('lifetime_points')->default(0)->after('points_balance');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['points_balance', 'lifetime_points']);
        });
    }
};
