<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('ticket_token', 64)->nullable()->unique()->after('folio');
        });

        // Backfill existing ventas
        \DB::table('ventas')->whereNull('ticket_token')->orderBy('id')->each(function ($row) {
            \DB::table('ventas')->where('id', $row->id)->update([
                'ticket_token' => Str::random(48),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('ticket_token');
        });
    }
};
