<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planes', function (Blueprint $table) {
            // -1 = ilimitado, 0 = sin facturación, N = timbres/mes incluidos
            $table->integer('timbres_incluidos')->default(0)->after('max_usuarios');
        });
    }

    public function down(): void
    {
        Schema::table('planes', function (Blueprint $table) {
            $table->dropColumn('timbres_incluidos');
        });
    }
};
