<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_meli_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_meli_id')->constrained('producto_meli')->onDelete('cascade');
            $table->foreignId('producto_id')->constrained('productos')->onDelete('cascade');
            $table->unsignedInteger('cantidad')->default(1);
            $table->timestamps();
            $table->unique(['producto_meli_id', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_meli_kits');
    }
};
