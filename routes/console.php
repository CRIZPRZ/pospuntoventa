<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ml:refresh-token')->hourly();

// Vencer cotizaciones cuya fecha_vencimiento ya pasó
Schedule::call(function () {
    \App\Models\Cotizacion::withoutGlobalScopes()
        ->whereIn('status', ['borrador', 'enviada'])
        ->whereNotNull('fecha_vencimiento')
        ->whereDate('fecha_vencimiento', '<', now()->toDateString())
        ->update(['status' => 'vencida']);
})->dailyAt('01:00')->name('vencer-cotizaciones');
