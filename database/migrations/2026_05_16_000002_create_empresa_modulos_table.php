<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_modulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('modulo_key');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'modulo_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_modulos');
    }
};
