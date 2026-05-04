<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mercado_libre_config', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->string('client_secret');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->bigInteger('seller_id')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('site_id')->default('MLM');
            $table->string('callback_url')->nullable();
            $table->boolean('active')->default(false);
            $table->boolean('auto_sync_stock')->default(true);
            $table->boolean('auto_publish')->default(false);
            $table->timestamps();
        });

        Schema::create('producto_meli', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('meli_item_id')->unique();
            $table->string('status')->default('active');
            $table->string('listing_type_id')->nullable();
            $table->decimal('price_usd', 10, 2)->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['producto_id', 'meli_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_meli');
        Schema::dropIfExists('mercado_libre_config');
    }
};
