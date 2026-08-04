<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->string('license_key')->unique();
            $table->string('status')->default('active');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->unsignedInteger('max_devices')->default(1);
            $table->json('plan_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('empresa_id');
            $table->index(['status', 'grace_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
