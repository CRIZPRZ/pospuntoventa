<?php

use App\Http\Controllers\TicketPublicoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public ticket viewers — no auth, token is the access key
// Specific routes MUST come before generic /t/{token}
Route::get('/t/cotizacion/{token}',        [TicketPublicoController::class, 'showCotizacion'])->name('ticket.cotizacion');
Route::get('/t/cotizacion/{token}/pdf',    [TicketPublicoController::class, 'downloadCotizacion'])->name('ticket.cotizacion.pdf');
Route::post('/t/cotizacion/{token}/aceptar',  [TicketPublicoController::class, 'aceptarCotizacion'])->name('ticket.cotizacion.aceptar');
Route::post('/t/cotizacion/{token}/rechazar', [TicketPublicoController::class, 'rechazarCotizacion'])->name('ticket.cotizacion.rechazar');
Route::get('/t/pedido/{token}',            [TicketPublicoController::class, 'showPedido'])->name('ticket.pedido');
Route::get('/t/{token}',                   [TicketPublicoController::class, 'showVenta'])->name('ticket.venta');
