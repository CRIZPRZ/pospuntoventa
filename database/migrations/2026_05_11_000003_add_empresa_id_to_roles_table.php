<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('empresa_id')->nullable()->after('id');
            $table->foreign('empresa_id')->references('id')->on('empresas')->nullOnDelete();
            // Allow same role name across tenants
            $table->dropUnique(['name', 'guard_name']);
            $table->unique(['name', 'guard_name', 'empresa_id']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['empresa_id']);
            $table->dropUnique(['name', 'guard_name', 'empresa_id']);
            $table->dropColumn('empresa_id');
            $table->unique(['name', 'guard_name']);
        });
    }
};
