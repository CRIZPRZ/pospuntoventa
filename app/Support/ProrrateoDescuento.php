<?php

namespace App\Support;

/**
 * Reparte un descuento global entre partidas, proporcional a su importe.
 *
 * Se usa al facturar: el CFDI debe sumar lo que realmente se cobró. Los
 * descuentos por línea ya vienen dentro del importe de cada partida; lo que
 * falta repartir es el descuento a nivel venta (ej. canje de puntos).
 */
class ProrrateoDescuento
{
    /**
     * @param  array<int|string, float>  $importes  Importe final de cada partida (ya con descuento por línea).
     * @param  float  $totalFinal  Total que se cobró.
     * @return array<int|string, float>  Importes ajustados (2 decimales) que suman exactamente $totalFinal.
     */
    public static function repartir(array $importes, float $totalFinal): array
    {
        $importes   = array_map(fn ($v) => round(max(0, (float) $v), 2), $importes);
        $suma       = round(array_sum($importes), 2);
        $totalFinal = round(max(0, $totalFinal), 2);

        if ($suma <= 0 || $totalFinal >= $suma) {
            return $importes;
        }

        $descuento = round($suma - $totalFinal, 2);
        $resultado = [];
        $repartido = 0.0;
        $mayorKey  = array_keys($importes, max($importes))[0];

        foreach ($importes as $key => $importe) {
            $parte           = round($descuento * ($importe / $suma), 2);
            $resultado[$key] = round($importe - $parte, 2);
            $repartido      += $parte;
        }

        // El residuo de redondeo lo absorbe la partida más grande.
        $resultado[$mayorKey] = round($resultado[$mayorKey] - round($descuento - $repartido, 2), 2);

        return $resultado;
    }
}
