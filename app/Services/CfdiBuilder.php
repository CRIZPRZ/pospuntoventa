<?php

namespace App\Services;

use App\Models\Venta;
use Carbon\Carbon;

class CfdiBuilder
{
    public function build(Venta $venta, array $config, array $receptor = []): array
    {
        $empresa     = $config['empresa'] ?? [];
        $facturacion = $config['facturacion'] ?? [];
        $tasaIva     = ($config['pos']['impuesto'] ?? 16) / 100;

        $conceptos      = $this->buildConceptos($venta, $tasaIva);
        $subtotalBase   = collect($conceptos)->sum(fn($c) => (float) $c['Importe']);
        $totalImpuestos = collect($conceptos)->sum(fn($c) => (float) $c['Impuestos']['Traslados']['Traslado']['Importe']);
        $total          = round($subtotalBase + $totalImpuestos, 2);

        return [
            'Version'          => '4.0',
            'Serie'            => $facturacion['serie'] ?? 'A',
            'Folio'            => $venta->folio,
            'Fecha'            => Carbon::now()->format('Y-m-d\TH:i:s'),
            'FormaPago'        => $this->mapFormaPago($venta->tipo_pago),
            'SubTotal'         => $this->fmt($subtotalBase),
            'Moneda'           => 'MXN',
            'Total'            => $this->fmt($total),
            'TipoDeComprobante'=> 'I',
            'Exportacion'      => '01',
            'MetodoPago'       => 'PUE',
            'LugarExpedicion'  => $facturacion['codigo_postal'] ?? '00000',
            'Emisor'           => [
                'Rfc'          => strtoupper(trim($empresa['rfc'] ?? '')),
                'Nombre'       => strtoupper(trim($empresa['nombre'] ?? '')),
                'RegimenFiscal'=> $facturacion['regimen_fiscal'] ?? '612',
            ],
            'Receptor'         => $this->buildReceptor($venta, $receptor, $facturacion),
            'Conceptos'        => ['Concepto' => $conceptos],
            'Impuestos'        => [
                'TotalImpuestosTrasladados' => $this->fmt($totalImpuestos),
                'Traslados'                => [
                    'Traslado' => [
                        'Base'        => $this->fmt($subtotalBase),
                        'Impuesto'    => '002',
                        'TipoFactor'  => 'Tasa',
                        'TasaOCuota'  => number_format($tasaIva, 6, '.', ''),
                        'Importe'     => $this->fmt($totalImpuestos),
                    ],
                ],
            ],
        ];
    }

    private function buildConceptos(Venta $venta, float $tasaIva): array
    {
        return $venta->items->map(function ($item) use ($tasaIva) {
            $valorUnitario = round((float) $item->precio_unitario / (1 + $tasaIva), 6);
            $importe       = round($valorUnitario * $item->cantidad, 2);
            $impImporte    = round($importe * $tasaIva, 2);

            $claveProd  = $item->producto?->clave_sat        ?? '01010101';
            $claveUnidad= $item->producto?->clave_unidad_sat ?? 'H87';
            $noId       = $item->producto?->codigo           ?? $item->producto?->codigo_barras ?? '';

            $concepto = [
                'ClaveProdServ'   => $claveProd,
                'Cantidad'        => (string) $item->cantidad,
                'ClaveUnidad'     => $claveUnidad,
                'Descripcion'     => mb_strtoupper($item->nombre_producto),
                'ValorUnitario'   => $this->fmt($valorUnitario),
                'Importe'         => $this->fmt($importe),
                'ObjetoImp'       => '02',
                'Impuestos'       => [
                    'Traslados' => [
                        'Traslado' => [
                            'Base'       => $this->fmt($importe),
                            'Impuesto'   => '002',
                            'TipoFactor' => 'Tasa',
                            'TasaOCuota' => number_format($tasaIva, 6, '.', ''),
                            'Importe'    => $this->fmt($impImporte),
                        ],
                    ],
                ],
            ];

            if ($noId) {
                $concepto = array_merge(['NoIdentificacion' => $noId], $concepto);
            }

            return $concepto;
        })->values()->toArray();
    }

    private function buildReceptor(Venta $venta, array $receptor, array $facturacion): array
    {
        $rfc = strtoupper(trim($receptor['rfc'] ?? $venta->cliente?->rfc ?? ''));

        if ($rfc && $rfc !== 'XAXX010101000') {
            return [
                'Rfc'                    => $rfc,
                'Nombre'                 => strtoupper(trim($receptor['nombre'] ?? $venta->cliente?->nombre ?? '')),
                'DomicilioFiscalReceptor'=> $receptor['codigo_postal'] ?? $venta->cliente?->codigo_postal ?? '00000',
                'RegimenFiscalReceptor'  => $receptor['regimen_fiscal'] ?? $venta->cliente?->regimen_fiscal ?? '616',
                'UsoCFDI'                => $receptor['uso_cfdi'] ?? $venta->cliente?->uso_cfdi ?? 'G03',
            ];
        }

        return [
            'Rfc'                    => 'XAXX010101000',
            'Nombre'                 => 'PUBLICO EN GENERAL',
            'DomicilioFiscalReceptor'=> $facturacion['codigo_postal'] ?? '00000',
            'RegimenFiscalReceptor'  => '616',
            'UsoCFDI'                => 'S01',
        ];
    }

    private function mapFormaPago(string $tipo): string
    {
        return match ($tipo) {
            'efectivo' => '01',
            'tarjeta'  => '04',
            'credito'  => '99',
            default    => '99',
        };
    }

    private function fmt(float $val): string
    {
        return number_format($val, 2, '.', '');
    }
}
