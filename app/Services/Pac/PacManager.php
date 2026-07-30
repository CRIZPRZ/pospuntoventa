<?php

namespace App\Services\Pac;

use App\Models\Empresa;

/**
 * Resuelve qué PAC usar para una empresa según `empresa.pac_provider`,
 * controlado solo por superadmin. También resuelve por nombre de PAC
 * (para descarga/cancelación del PAC que originalmente emitió el CFDI).
 */
class PacManager
{
    public static function for(?Empresa $empresa): PacContract
    {
        return self::make($empresa?->pac_provider ?? 'facturama');
    }

    public static function make(string $provider): PacContract
    {
        return match ($provider) {
            'facturapi'  => new FacturapiPac(),
            'sw_sapiens' => new SwSapiensPac(),
            default      => new FacturamaPac(),
        };
    }
}
