<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('camaras', function (Blueprint $table) {
            $table->enum('status', ['pending', 'connected', 'error'])->default('pending')->after('orden');
            $table->string('last_error')->nullable()->after('status');
            $table->timestamp('last_checked')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('camaras', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_error', 'last_checked']);
        });
    }
};
