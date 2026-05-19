<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->decimal('precio_mensual', 10, 2)->default(0);
            $table->integer('max_sucursales')->default(1);  // -1 = ilimitado
            $table->integer('max_usuarios')->default(5);    // -1 = ilimitado
            $table->json('modulos')->nullable();
            $table->string('color')->default('blue');
            $table->string('stripe_price_id')->nullable(); // ID de Price en Stripe (price_xxx)
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planes');
    }
};
