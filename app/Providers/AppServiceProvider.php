<?php

namespace App\Providers;

use App\Events\VentaCompletada;
use App\Listeners\SyncStockToMercadoLibre;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(
            VentaCompletada::class,
            SyncStockToMercadoLibre::class,
        );

        // SendVentaTicketToWhatsApp deshabilitado — el POS pregunta al cajero
        // si quiere enviar ticket por WA. El listener causaba doble mensaje.
    }
}
