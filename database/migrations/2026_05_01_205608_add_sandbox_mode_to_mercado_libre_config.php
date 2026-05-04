<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mercado_libre_config', function (Blueprint $table) {
            $table->boolean('sandbox_mode')->default(false)->after('auto_publish');
            $table->json('test_user')->nullable()->after('sandbox_mode');
        });
    }

    public function down(): void
    {
        Schema::table('mercado_libre_config', function (Blueprint $table) {
            $table->dropColumn(['sandbox_mode', 'test_user']);
        });
    }
};
