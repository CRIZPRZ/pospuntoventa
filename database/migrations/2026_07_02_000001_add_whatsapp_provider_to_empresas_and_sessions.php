<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            if (!Schema::hasColumn('empresas', 'whatsapp_provider')) {
                $table->string('whatsapp_provider')->default('cloud_api')->after('pac_provider');
            }
        });

        Schema::table('whatsapp_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_configs', 'session_key')) {
                $table->string('session_key')->nullable()->after('provider');
            }
            if (!Schema::hasColumn('whatsapp_configs', 'connected_at')) {
                $table->timestamp('connected_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('whatsapp_configs', 'disconnected_at')) {
                $table->timestamp('disconnected_at')->nullable()->after('connected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_configs', 'disconnected_at')) {
                $table->dropColumn('disconnected_at');
            }
            if (Schema::hasColumn('whatsapp_configs', 'connected_at')) {
                $table->dropColumn('connected_at');
            }
            if (Schema::hasColumn('whatsapp_configs', 'session_key')) {
                $table->dropColumn('session_key');
            }
        });

        Schema::table('empresas', function (Blueprint $table) {
            if (Schema::hasColumn('empresas', 'whatsapp_provider')) {
                $table->dropColumn('whatsapp_provider');
            }
        });
    }
};
