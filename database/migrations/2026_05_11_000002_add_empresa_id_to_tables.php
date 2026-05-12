<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add deferred ventas→clientes FK (clientes didn't exist when ventas was first created)
        Schema::table('ventas', function (Blueprint $t) {
            $t->foreign('cliente_id')->references('id')->on('clientes')->nullOnDelete();
        });

        $tables = [
            'users', 'productos', 'categorias', 'clientes',
            'ventas', 'cajas', 'abonos', 'proveedores',
            'pagos_proveedores', 'mercado_libre_config', 'cotizaciones',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'empresa_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('empresa_id')->nullable()->after('id')->constrained('empresas')->nullOnDelete();
                });
            }
        }

        // Drop global folio unique, make it unique per empresa
        if (Schema::hasTable('ventas')) {
            Schema::table('ventas', function (Blueprint $t) {
                try { $t->dropUnique(['folio']); } catch (\Exception $e) {}
                $t->unique(['empresa_id', 'folio']);
            });
        }

        if (Schema::hasTable('cotizaciones')) {
            Schema::table('cotizaciones', function (Blueprint $t) {
                try { $t->dropUnique(['folio']); } catch (\Exception $e) {}
                $t->unique(['empresa_id', 'folio']);
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'users', 'productos', 'categorias', 'clientes',
            'ventas', 'cajas', 'abonos', 'proveedores',
            'pagos_proveedores', 'mercado_libre_config', 'cotizaciones',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'empresa_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropForeign(['empresa_id']);
                    $t->dropColumn('empresa_id');
                });
            }
        }
    }
};
