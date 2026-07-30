<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timbres_consumo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->unsignedBigInteger('venta_id')->nullable();
            $table->string('pac', 20);
            $table->string('uuid', 60)->nullable();
            $table->timestamps();

            // Conteo mensual rápido por empresa
            $table->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timbres_consumo');
    }
};
