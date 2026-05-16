<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // users: columna para sucursal activa por defecto
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                  ->constrained('sucursales')->nullOnDelete();
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                  ->constrained('sucursales')->nullOnDelete();
        });

        Schema::table('cajas', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                  ->constrained('sucursales')->nullOnDelete();
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                  ->constrained('sucursales')->nullOnDelete();
        });

        // Crear sucursal principal para cada empresa existente
        // y asignar todos sus datos a ella
        $empresas = DB::table('empresas')->get();

        foreach ($empresas as $empresa) {
            $sucursalId = DB::table('sucursales')->insertGetId([
                'empresa_id'   => $empresa->id,
                'nombre'       => 'Sucursal Principal',
                'es_principal' => true,
                'activo'       => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            DB::table('users')->where('empresa_id', $empresa->id)
                ->whereNull('sucursal_id')
                ->update(['sucursal_id' => $sucursalId]);

            DB::table('ventas')->where('empresa_id', $empresa->id)
                ->whereNull('sucursal_id')
                ->update(['sucursal_id' => $sucursalId]);

            DB::table('cajas')->where('empresa_id', $empresa->id)
                ->whereNull('sucursal_id')
                ->update(['sucursal_id' => $sucursalId]);

            DB::table('clientes')->where('empresa_id', $empresa->id)
                ->whereNull('sucursal_id')
                ->update(['sucursal_id' => $sucursalId]);
        }
    }

    public function down(): void
    {
        Schema::table('clientes', fn (Blueprint $t) => $t->dropForeign(['sucursal_id']));
        Schema::table('cajas',    fn (Blueprint $t) => $t->dropForeign(['sucursal_id']));
        Schema::table('ventas',   fn (Blueprint $t) => $t->dropForeign(['sucursal_id']));
        Schema::table('users',    fn (Blueprint $t) => $t->dropForeign(['sucursal_id']));

        Schema::table('clientes', fn (Blueprint $t) => $t->dropColumn('sucursal_id'));
        Schema::table('cajas',    fn (Blueprint $t) => $t->dropColumn('sucursal_id'));
        Schema::table('ventas',   fn (Blueprint $t) => $t->dropColumn('sucursal_id'));
        Schema::table('users',    fn (Blueprint $t) => $t->dropColumn('sucursal_id'));
    }
};
