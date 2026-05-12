<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mercado_libre_config', function (Blueprint $table) {
            $table->text('test_access_token')->nullable()->after('test_user');
            $table->string('test_refresh_token')->nullable()->after('test_access_token');
            $table->timestamp('test_token_expires_at')->nullable()->after('test_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('mercado_libre_config', function (Blueprint $table) {
            $table->dropColumn(['test_access_token', 'test_refresh_token', 'test_token_expires_at']);
        });
    }
};
