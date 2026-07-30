<?php

namespace App\Providers;

use App\Events\VentaCompletada;
use App\Listeners\SyncStockToMercadoLibre;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('register', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perHour(20)->by($request->ip()),
            ];
        });

        Event::listen(
            VentaCompletada::class,
            SyncStockToMercadoLibre::class,
        );

        // SendVentaTicketToWhatsApp deshabilitado — el POS pregunta al cajero
        // si quiere enviar ticket por WA. El listener causaba doble mensaje.
    }
}
