<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\LoyaltyTransaction;
use App\Models\Venta;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class LoyaltyService
{
    public function config(int $empresaId): array
    {
        $cacheKey = 'ventas_configuracion_' . $empresaId;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            $stored = $cached['loyalty'] ?? null;
        } else {
            $row = Configuracion::where('empresa_id', $empresaId)->first();
            $stored = $row?->config['loyalty'] ?? null;
        }

        return array_replace([
            'activo'           => false,
            'modo'             => 'total',
            'monto_por_punto'  => 10,
            'puntos_otorgados' => 1,
            'valor_punto'      => 1,
        ], $stored ?? []);
    }

    /**
     * Calcula puntos ganados por una venta según el modo configurado.
     * $items: Collection de arrays con keys producto (Model), cantidad, subtotal.
     */
    public function calcularPuntosGanados(array $config, float $total, Collection $items): int
    {
        if (!$config['activo']) {
            return 0;
        }

        if ($config['modo'] === 'producto') {
            return (int) $items->sum(function ($item) use ($config) {
                $puntosProducto = $item['producto']->puntos_por_unidad;
                if ($puntosProducto !== null) {
                    return $puntosProducto * $item['cantidad'];
                }

                return (int) floor($item['subtotal'] / $config['monto_por_punto']) * $config['puntos_otorgados'];
            });
        }

        return (int) floor($total / $config['monto_por_punto']) * $config['puntos_otorgados'];
    }

    public function registrarGanados(array $config, Cliente $cliente, Venta $venta, int $puntos, ?int $sucursalId = null, ?int $userId = null): void
    {
        if ($puntos <= 0) {
            return;
        }

        $cliente->increment('points_balance', $puntos);
        $cliente->increment('lifetime_points', $puntos);

        LoyaltyTransaction::create([
            'empresa_id'  => $cliente->empresa_id,
            'cliente_id'  => $cliente->id,
            'venta_id'    => $venta->id,
            'sucursal_id' => $sucursalId,
            'type'        => 'earned',
            'points'      => $puntos,
            'monto'       => $venta->total,
            'descripcion' => 'Puntos ganados por venta ' . $venta->folio,
            'created_by'  => $userId,
        ]);
    }

    /**
     * Valida saldo y calcula el monto de descuento por canjear $puntosACanjear.
     * No muta nada — solo valida y calcula.
     */
    public function calcularDescuentoPuntos(array $config, Cliente $cliente, int $puntosACanjear): float
    {
        if (!$config['activo']) {
            abort(422, 'El programa de puntos no está activo.');
        }

        if ($puntosACanjear > $cliente->points_balance) {
            abort(422, "Puntos insuficientes. Disponibles: {$cliente->points_balance}, solicitados: {$puntosACanjear}.");
        }

        return round($puntosACanjear * $config['valor_punto'], 2);
    }

    /**
     * Descuenta el saldo de puntos del cliente y registra el movimiento en el ledger.
     * Llamar solo después de que la venta ya existe.
     */
    public function aplicarCanje(Cliente $cliente, Venta $venta, int $puntosACanjear, float $descuento, ?int $sucursalId = null, ?int $userId = null): void
    {
        if ($puntosACanjear <= 0) {
            return;
        }

        $cliente->decrement('points_balance', $puntosACanjear);

        LoyaltyTransaction::create([
            'empresa_id'  => $cliente->empresa_id,
            'cliente_id'  => $cliente->id,
            'venta_id'    => $venta->id,
            'sucursal_id' => $sucursalId,
            'type'        => 'redeemed',
            'points'      => -$puntosACanjear,
            'monto'       => $descuento,
            'descripcion' => 'Puntos canjeados en venta ' . $venta->folio,
            'created_by'  => $userId,
        ]);
    }
}
