<?php

namespace App\Services\Pac;

use App\Models\Empresa;

/**
 * Resuelve qué PAC usar para una empresa según `empresa.pac_provider`,
 * controlado solo por superadmin. También resuelve por nombre de PAC
 * (para descarga/cancelación del PAC que originalmente emitió el CFDI).
 *
 * PAC por defecto: CFDI Express (desde 2026-10-07).
 */
class PacManager
{
    public const DEFAULT = 'cfdi_express';

    public const PROVIDERS = ['cfdi_express', 'facturama', 'sw_sapiens', 'facturapi'];

    public static function for(?Empresa $empresa): PacContract
    {
        return self::make($empresa?->pac_provider ?: self::DEFAULT);
    }

    public static function make(?string $provider): PacContract
    {
        return match ($provider ?: self::DEFAULT) {
            'facturapi'  => new FacturapiPac(),
            'sw_sapiens' => new SwSapiensPac(),
            'facturama'  => new FacturamaPac(),
            default      => new CfdiExpressPac(),
        };
    }
}
