<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // cotizaciones: agregar sucursal_id (empresa_id ya existe via 000002)
        if (!Schema::hasColumn('cotizaciones', 'sucursal_id')) {
            Schema::table('cotizaciones', function (Blueprint $table) {
                $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                      ->constrained('sucursales')->nullOnDelete();
            });
        }

        // pedidos: agregar sucursal_id (empresa_id ya existe en create migration)
        if (!Schema::hasColumn('pedidos', 'sucursal_id')) {
            Schema::table('pedidos', function (Blueprint $table) {
                $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                      ->constrained('sucursales')->nullOnDelete();
            });
        }

        // pagos_proveedores: agregar sucursal_id (empresa_id ya existe via 000002)
        if (!Schema::hasColumn('pagos_proveedores', 'sucursal_id')) {
            Schema::table('pagos_proveedores', function (Blueprint $table) {
                $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                      ->constrained('sucursales')->nullOnDelete();
            });
        }

        // Poblar sucursal_id en cotizaciones existentes
        DB::statement('
            UPDATE cotizaciones c
            JOIN sucursales s ON s.empresa_id = c.empresa_id AND s.es_principal = 1
            SET c.sucursal_id = s.id
            WHERE c.sucursal_id IS NULL AND c.empresa_id IS NOT NULL
        ');

        // Poblar sucursal_id en pedidos existentes
        DB::statement('
            UPDATE pedidos p
            JOIN sucursales s ON s.empresa_id = p.empresa_id AND s.es_principal = 1
            SET p.sucursal_id = s.id
            WHERE p.sucursal_id IS NULL AND p.empresa_id IS NOT NULL
        ');

        // Poblar sucursal_id en pagos_proveedores existentes
        DB::statement('
            UPDATE pagos_proveedores pp
            JOIN sucursales s ON s.empresa_id = pp.empresa_id AND s.es_principal = 1
            SET pp.sucursal_id = s.id
            WHERE pp.sucursal_id IS NULL AND pp.empresa_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        foreach (['cotizaciones', 'pedidos', 'pagos_proveedores'] as $table) {
            if (Schema::hasColumn($table, 'sucursal_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropForeign(['sucursal_id']);
                    $t->dropColumn('sucursal_id');
                });
            }
        }
    }
};
