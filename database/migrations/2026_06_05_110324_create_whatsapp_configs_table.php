<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->string('provider')->default('meta');
            $table->string('business_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('display_name')->nullable();
            $table->string('connected_phone_number')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('whatsapp_business_account_id')->nullable();
            $table->text('access_token')->nullable();
            $table->string('status')->default('disconnected');
            $table->timestamp('last_test_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'sucursal_id']);
            $table->index(['empresa_id', 'status']);
            $table->index(['empresa_id', 'sucursal_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_configs');
    }
};
