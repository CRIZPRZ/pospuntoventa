<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->decimal('credito_timbres', 10, 2)->default(0)->after('timbres_extra');
            $table->decimal('costo_timbre', 8, 2)->default(2.00)->after('credito_timbres');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['credito_timbres', 'costo_timbre']);
        });
    }
};
