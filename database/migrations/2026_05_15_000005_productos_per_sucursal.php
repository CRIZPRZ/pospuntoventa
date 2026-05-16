<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('empresa_id')
                  ->constrained('sucursales')->nullOnDelete();
        });

        // Asignar productos existentes a la sucursal principal de su empresa
        $empresas = DB::table('empresas')->get();
        foreach ($empresas as $empresa) {
            $sucursalId = DB::table('sucursales')
                ->where('empresa_id', $empresa->id)
                ->where('es_principal', true)
                ->value('id');

            if ($sucursalId) {
                DB::table('productos')
                    ->where('empresa_id', $empresa->id)
                    ->whereNull('sucursal_id')
                    ->update(['sucursal_id' => $sucursalId]);
            }
        }

        Schema::dropIfExists('producto_sucursal');
    }

    public function down(): void
    {
        Schema::create('producto_sucursal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->integer('stock')->nullable();
            $table->integer('stock_minimo')->nullable();
            $table->decimal('precio', 12, 2)->nullable();
            $table->decimal('precio_mayoreo', 12, 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['producto_id', 'sucursal_id']);
        });

        Schema::table('productos', fn (Blueprint $t) => $t->dropForeign(['sucursal_id']));
        Schema::table('productos', fn (Blueprint $t) => $t->dropColumn('sucursal_id'));
    }
};
