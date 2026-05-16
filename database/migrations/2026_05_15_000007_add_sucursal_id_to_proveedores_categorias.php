<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                  ->constrained('sucursales')->nullOnDelete();
        });

        Schema::table('categorias', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                  ->constrained('sucursales')->nullOnDelete();
        });

        // Asignar a sucursal principal de cada empresa
        $empresas = DB::table('empresas')->get();
        foreach ($empresas as $empresa) {
            $sucursalId = DB::table('sucursales')
                ->where('empresa_id', $empresa->id)
                ->where('es_principal', true)
                ->value('id');

            if (!$sucursalId) continue;

            DB::table('proveedores')
                ->where('empresa_id', $empresa->id)
                ->whereNull('sucursal_id')
                ->update(['sucursal_id' => $sucursalId]);

            DB::table('categorias')
                ->where('empresa_id', $empresa->id)
                ->whereNull('sucursal_id')
                ->update(['sucursal_id' => $sucursalId]);
        }
    }

    public function down(): void
    {
        Schema::table('proveedores', fn (Blueprint $t) => $t->dropForeign(['sucursal_id']));
        Schema::table('proveedores', fn (Blueprint $t) => $t->dropColumn('sucursal_id'));
        Schema::table('categorias',  fn (Blueprint $t) => $t->dropForeign(['sucursal_id']));
        Schema::table('categorias',  fn (Blueprint $t) => $t->dropColumn('sucursal_id'));
    }
};
