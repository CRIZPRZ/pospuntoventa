<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->decimal('efectivo_contado', 10, 2)->nullable()->after('total_ventas');
            $table->decimal('diferencia', 10, 2)->nullable()->after('efectivo_contado');
        });
    }

    public function down(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropColumn(['efectivo_contado', 'diferencia']);
        });
    }
};
