<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sucursales', function (Blueprint $table) {
            $table->string('agent_token', 64)->nullable()->unique()->after('mediamtx_url');
            $table->string('agent_version')->nullable()->after('agent_token');
            $table->timestamp('agent_last_seen')->nullable()->after('agent_version');
        });

        // Generar token para sucursales existentes
        \DB::table('sucursales')->whereNull('agent_token')->each(function ($s) {
            \DB::table('sucursales')
                ->where('id', $s->id)
                ->update(['agent_token' => Str::random(48)]);
        });
    }

    public function down(): void
    {
        Schema::table('sucursales', function (Blueprint $table) {
            $table->dropColumn(['agent_token', 'agent_version', 'agent_last_seen']);
        });
    }
};
