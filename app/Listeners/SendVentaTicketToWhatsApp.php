<?php

namespace App\Listeners;

use App\Events\VentaCompletada;
use App\Models\Venta;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendVentaTicketToWhatsApp implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        protected WhatsAppService $whatsAppService,
    ) {}

    public function handle(VentaCompletada $event): void
    {
        $venta = $event->venta->loadMissing(['cliente', 'items']);

        // Deduplication guard: prevent double-send if event fires more than once for same venta
        $dedupKey = "wa_ticket_sent_{$venta->id}";
        if (Cache::has($dedupKey)) {
            return;
        }
        Cache::put($dedupKey, true, now()->addMinutes(5));

        $cliente = $venta->cliente;

        if (!$cliente || empty($cliente->telefono)) {
            return;
        }

        $publicConfig = $this->whatsAppService->resolvePublicConfig(
            (int) $venta->empresa_id,
            $venta->sucursal_id ? (int) $venta->sucursal_id : null,
        );

        if (!($publicConfig['auto_send_ticket'] ?? false)) {
            return;
        }

        $technicalConfig = $this->whatsAppService->resolveTechnicalConfig(
            (int) $venta->empresa_id,
            $venta->sucursal_id ? (int) $venta->sucursal_id : null,
        );

        if (!$this->whatsAppService->isConnected($technicalConfig)) {
            return;
        }

        $businessName = $this->whatsAppService->resolveBusinessName(
            (int) $venta->empresa_id,
            $venta->sucursal_id ? (int) $venta->sucursal_id : null,
            $technicalConfig,
            $publicConfig,
        );

        [$body, $ticketUrl] = self::buildTicketContent($venta, $businessName);

        try {
            if ($ticketUrl) {
                $this->whatsAppService->sendTicketMessage($technicalConfig, $cliente->telefono, $body, $ticketUrl);
            } else {
                $this->whatsAppService->sendTextMessage($technicalConfig, $cliente->telefono, $body);
            }

            $technicalConfig->update(['status' => 'connected', 'last_error' => null]);
        } catch (\Throwable $e) {
            $technicalConfig->update(['last_error' => $e->getMessage()]);

            Log::warning('No se pudo enviar ticket por WhatsApp después de una venta.', [
                'venta_id' => $venta->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public static function buildTicketContent(Venta $venta, string $businessName): array
    {
        $itemLines = $venta->items->take(5)->map(function ($item) {
            return '▸ ' . $item->nombre_producto . ' × ' . (int) $item->cantidad
                . ' — $' . number_format((float) ($item->precio_unitario * $item->cantidad), 2);
        })->implode("\n");

        if ($venta->items->count() > 5) {
            $itemLines .= "\n▸ _y " . ($venta->items->count() - 5) . ' producto(s) más_';
        }

        $body = implode("\n", [
            "✅ *¡Gracias por tu compra en {$businessName}!*",
            '',
            "🧾 *Folio:* {$venta->folio}",
            '💰 *Total:* $' . number_format((float) $venta->total, 2),
            '',
            '*Productos:*',
            $itemLines,
        ]);

        $ticketUrl = $venta->ticket_token
            ? url('/t/' . $venta->ticket_token)
            : null;

        return [$body, $ticketUrl];
    }
}
