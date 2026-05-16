<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_sucursal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            // null = usar stock del producto global; valor = stock propio de sucursal
            $table->integer('stock')->nullable();
            $table->integer('stock_minimo')->nullable();
            // null = usar precio base del producto; valor = precio override de sucursal
            $table->decimal('precio', 12, 2)->nullable();
            $table->decimal('precio_mayoreo', 12, 2)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['producto_id', 'sucursal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_sucursal');
    }
};
