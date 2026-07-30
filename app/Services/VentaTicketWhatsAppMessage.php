<?php

namespace App\Services;

use App\Models\Venta;

class VentaTicketWhatsAppMessage
{
    public static function build(Venta $venta, string $businessName): array
    {
        $businessName = trim($businessName);
        $greeting = $businessName !== ''
            ? "✅ *¡Gracias por tu compra en {$businessName}!*"
            : '✅ *¡Gracias por tu compra!*';

        $itemLines = $venta->items->take(5)->map(function ($item) {
            return '▸ ' . $item->nombre_producto . ' × ' . (int) $item->cantidad
                . ' — $' . number_format((float) ($item->precio_unitario * $item->cantidad), 2);
        })->implode("\n");

        if ($venta->items->count() > 5) {
            $itemLines .= "\n▸ _y " . ($venta->items->count() - 5) . ' producto(s) más_';
        }

        $body = implode("\n", [
            $greeting,
            '',
            "🧾 *Folio:* {$venta->folio}",
            '💰 *Total:* $' . number_format((float) $venta->total, 2),
            '',
            '*Productos:*',
            $itemLines,
        ]);

        $ticketUrl = $venta->ticket_token
            ? WhatsAppService::publicUrl('/t/' . $venta->ticket_token)
            : null;

        return [$body, $ticketUrl];
    }
}
