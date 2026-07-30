<?php

namespace App\Services\Pac;

use App\Services\FacturapiService;

/**
 * PAC Facturapi — envuelve FacturapiService y traduce el invoice
 * normalizado al formato de Facturapi (customer + items con tax_included).
 */
class FacturapiPac implements PacContract
{
    public function __construct(private FacturapiService $fp = new FacturapiService()) {}

    public function key(): string
    {
        return 'facturapi';
    }

    public function setup(array $ctx): array
    {
        $orgId = $this->fp->crearOrganizacion($ctx['nombre'] ?? 'Mi Empresa');

        if (!empty($ctx['codigo_postal'])) {
            $this->fp->actualizarLegalOrg($orgId, [
                'name'       => mb_strtoupper($ctx['nombre'] ?? ''),
                'tax_system' => $ctx['regimen_fiscal'] ?? '601',
                'address'    => ['zip' => $ctx['codigo_postal']],
            ]);
        }

        return ['org_id' => $orgId];
    }

    public function subirCsd(array $ctx, string $cerBase64, string $keyBase64, string $password): void
    {
        $this->fp->subirCsd($ctx['org_id'], $cerBase64, $keyBase64, $password);
    }

    public function crearFactura(array $ctx, array $invoice): array
    {
        $payload = $this->buildPayload($invoice);
        $orgKey  = $ctx['org_key'];

        $resp = $this->fp->crearFactura($orgKey, $payload);

        $id  = $resp['id']   ?? null;
        $xml = $id ? $this->fp->descargarXml($orgKey, $id) : null;

        return [
            'uuid'   => $resp['uuid'] ?? null,
            'pac_id' => $id,
            'xml'    => $xml,
        ];
    }

    public function descargarXml(array $ctx, string $pacId): string
    {
        return $this->fp->descargarXml($ctx['org_key'], $pacId);
    }

    public function descargarPdf(array $ctx, string $pacId): string
    {
        return $this->fp->descargarPdf($ctx['org_key'], $pacId);
    }

    public function cancelarFactura(array $ctx, string $pacId, string $motivo, ?string $uuidRelacionado): array
    {
        return $this->fp->cancelarFactura($ctx['org_key'], $pacId, $motivo);
    }

    public function test(array $ctx): bool
    {
        return $this->fp->testOrg($ctx['org_key']);
    }

    /** Traduce el invoice normalizado al formato Facturapi (tax_included = precio ya trae IVA). */
    private function buildPayload(array $invoice): array
    {
        $tasa = (float) $invoice['tasa_iva'];

        $items = array_map(function (array $it) use ($tasa) {
            return [
                'product' => [
                    'description'  => mb_strtoupper($it['descripcion']),
                    'product_key'  => $it['clave_sat'],
                    'unit_key'     => $it['clave_unidad'],
                    'unit_name'    => $it['unidad'] ?? 'Pieza',
                    'price'        => round((float) $it['precio_con_iva'], 6),
                    'tax_included' => true,
                    'taxes'        => [['type' => 'IVA', 'rate' => $tasa, 'factor' => 'Tasa']],
                ],
                'quantity' => (float) $it['cantidad'],
            ];
        }, $invoice['items']);

        $r = $invoice['receptor'];

        $customer = $r['es_publico'] ? [
            'legal_name' => 'PUBLICO EN GENERAL',
            'tax_id'     => 'XAXX010101000',
            'tax_system' => '616',
            'address'    => ['zip' => $r['codigo_postal'] ?: '00000'],
        ] : [
            'legal_name' => mb_strtoupper($r['nombre']),
            'tax_id'     => $r['rfc'],
            'tax_system' => $r['regimen_fiscal'],
            'address'    => ['zip' => $r['codigo_postal'] ?: '00000'],
        ];

        return [
            'type'           => $invoice['tipo'],
            'customer'       => $customer,
            'items'          => $items,
            'payment_form'   => $invoice['forma_pago'],
            'payment_method' => $invoice['metodo_pago'],
            'use'            => $r['uso_cfdi'],
            'series'         => $invoice['serie'],
            'folio_number'   => $invoice['folio'],
        ];
    }
}
