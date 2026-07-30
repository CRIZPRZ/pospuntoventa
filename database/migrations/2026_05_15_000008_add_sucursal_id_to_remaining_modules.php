<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                  ->constrained('sucursales')->nullOnDelete();
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                  ->constrained('sucursales')->nullOnDelete();
        });

        Schema::table('abonos', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                  ->constrained('sucursales')->nullOnDelete();
        });

        Schema::table('pagos_proveedores', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                  ->constrained('sucursales')->nullOnDelete();
        });

        // Asignar a sucursal principal por empresa
        $empresas = DB::table('empresas')->get();
        foreach ($empresas as $empresa) {
            $sucursalId = DB::table('sucursales')
                ->where('empresa_id', $empresa->id)
                ->where('es_principal', true)
                ->value('id');

            if (!$sucursalId) continue;

            foreach (['cotizaciones', 'pedidos', 'pagos_proveedores'] as $tabla) {
                DB::table($tabla)
                    ->where('empresa_id', $empresa->id)
                    ->whereNull('sucursal_id')
                    ->update(['sucursal_id' => $sucursalId]);
            }
        }

        // Abonos no tienen empresa_id: asignar la sucursal mediante su venta.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("
                UPDATE abonos
                SET sucursal_id = (
                    SELECT ventas.sucursal_id
                    FROM ventas
                    WHERE ventas.id = abonos.venta_id
                )
                WHERE sucursal_id IS NULL AND venta_id IS NOT NULL
            ");
        } else {
            DB::statement("
                UPDATE abonos a
                INNER JOIN ventas v ON v.id = a.venta_id
                SET a.sucursal_id = v.sucursal_id
                WHERE a.sucursal_id IS NULL AND a.venta_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        foreach (['cotizaciones', 'pedidos', 'abonos', 'pagos_proveedores'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropForeign(['sucursal_id']));
            Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn('sucursal_id'));
        }
    }
};
