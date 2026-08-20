<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Venta;

class VentaTicketWhatsAppMessage
{
    public static function build(Venta $venta, string $businessName, ?Cliente $cliente = null): array
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

        $lines = [
            $greeting,
            '',
            "🧾 *Folio:* {$venta->folio}",
            '💰 *Total:* $' . number_format((float) $venta->total, 2),
            '',
            '*Productos:*',
            $itemLines,
        ];

        if ($cliente && ((int) $venta->puntos_ganados > 0 || (int) $venta->puntos_canjeados > 0)) {
            $lines[] = '';
            if ((int) $venta->puntos_canjeados > 0) {
                $lines[] = '🎁 *Puntos canjeados:* ' . (int) $venta->puntos_canjeados;
            }
            if ((int) $venta->puntos_ganados > 0) {
                $lines[] = '⭐ *Puntos ganados:* +' . (int) $venta->puntos_ganados;
            }
            $lines[] = '💳 *Puntos disponibles:* ' . (int) $cliente->points_balance;
        }

        $body = implode("\n", $lines);

        $ticketUrl = $venta->ticket_token
            ? WhatsAppService::publicUrl('/t/' . $venta->ticket_token)
            : null;

        return [$body, $ticketUrl];
    }
}
