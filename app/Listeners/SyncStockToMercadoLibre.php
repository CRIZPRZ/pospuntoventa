<?php

namespace App\Listeners;

use App\Events\VentaCompletada;
use App\Models\MercadoLibreConfig;
use App\Models\Producto;
use App\Services\MercadoLibreService;
use Illuminate\Support\Facades\Log;

class SyncStockToMercadoLibre
{
    public function __construct(
        protected MercadoLibreService $mlService,
    ) {}

    public function handle(VentaCompletada $event): void
    {
        $config = MercadoLibreConfig::first();

        if (! $config || ! $config->auto_sync_stock) {
            return;
        }

        $tokenValid = $config->sandbox_mode ? $config->hasValidTestToken() : $config->hasValidToken();
        if (! $tokenValid) {
            Log::warning('Token de ML inválido o expirado, no se sincronizó stock');
            return;
        }

        $productoIds = $event->productoIds ?: $event->venta->items->pluck('producto_id')->toArray();

        $productos = Producto::with('mercadoLibre')
            ->whereIn('id', $productoIds)
            ->get();

        foreach ($productos as $producto) {
            $meliActive = $producto->mercadoLibre->firstWhere('status', 'active');
            if (! $meliActive) {
                continue;
            }

            try {
                $this->mlService->updateStock($producto);
                Log::info("Stock sincronizado a ML: producto {$producto->nombre}, stock: {$producto->stock}");
            } catch (\Exception $e) {
                Log::error("Error sincronizando stock a ML: {$e->getMessage()}", [
                    'producto_id' => $producto->id,
                ]);
            }
        }
    }
}
