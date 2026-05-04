<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->foreignId('cerrada_por')->nullable()->after('cerrada_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('cancelada_por')->nullable()->after('estado')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropForeign(['cerrada_por']);
            $table->dropColumn('cerrada_por');
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropForeign(['cancelada_por']);
            $table->dropColumn('cancelada_por');
        });
    }
};
