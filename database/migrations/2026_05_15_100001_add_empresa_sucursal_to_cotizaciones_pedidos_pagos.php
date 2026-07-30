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

        foreach (['cotizaciones' => 'c', 'pedidos' => 'p', 'pagos_proveedores' => 'pp'] as $table => $alias) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement("
                    UPDATE {$table}
                    SET sucursal_id = (
                        SELECT sucursales.id
                        FROM sucursales
                        WHERE sucursales.empresa_id = {$table}.empresa_id
                          AND sucursales.es_principal = 1
                        LIMIT 1
                    )
                    WHERE sucursal_id IS NULL AND empresa_id IS NOT NULL
                ");
                continue;
            }

            DB::statement("
                UPDATE {$table} {$alias}
                JOIN sucursales s ON s.empresa_id = {$alias}.empresa_id AND s.es_principal = 1
                SET {$alias}.sucursal_id = s.id
                WHERE {$alias}.sucursal_id IS NULL AND {$alias}.empresa_id IS NOT NULL
            ");
        }
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
