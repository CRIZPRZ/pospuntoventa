<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->string('device_uuid');
            $table->string('device_name');
            $table->string('fingerprint');
            $table->string('platform');
            $table->string('app_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_token_issued_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['license_id', 'device_uuid']);
            $table->index(['license_id', 'is_active', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_devices');
    }
};
