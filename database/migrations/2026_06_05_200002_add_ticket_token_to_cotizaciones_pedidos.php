<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->string('ticket_token', 64)->nullable()->unique()->after('folio');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('ticket_token', 64)->nullable()->unique()->after('folio');
        });

        \DB::table('cotizaciones')->whereNull('ticket_token')->orderBy('id')->each(function ($row) {
            \DB::table('cotizaciones')->where('id', $row->id)->update(['ticket_token' => Str::random(48)]);
        });

        \DB::table('pedidos')->whereNull('ticket_token')->orderBy('id')->each(function ($row) {
            \DB::table('pedidos')->where('id', $row->id)->update(['ticket_token' => Str::random(48)]);
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', fn (Blueprint $t) => $t->dropColumn('ticket_token'));
        Schema::table('pedidos',      fn (Blueprint $t) => $t->dropColumn('ticket_token'));
    }
};
