<?php

namespace App\Services\Pac;

/**
 * Contrato común para Proveedores Autorizados de Certificación (PAC).
 *
 * El controlador construye un "invoice" normalizado (PAC-agnóstico) y un
 * "ctx" (datos del emisor + credenciales resueltas); cada implementación
 * traduce a su propio formato de API.
 *
 * Forma de $ctx (contexto del emisor):
 *   ambiente        => 'live' | 'test'
 *   rfc, nombre, regimen_fiscal, codigo_postal  => datos del emisor (tenant)
 *   org_id, org_key => solo Facturapi (organización + key resuelta)
 *
 * Forma de $invoice (normalizado):
 *   tipo, serie, folio, forma_pago, metodo_pago
 *   tasa_iva (decimal, ej. 0.16)
 *   emisor   => ['rfc','nombre','regimen_fiscal','codigo_postal']
 *   receptor => ['rfc','nombre','regimen_fiscal','codigo_postal','uso_cfdi','es_publico']
 *   items    => [['descripcion','clave_sat','clave_unidad','unidad','precio_con_iva','cantidad'], ...]
 */
interface PacContract
{
    /** Identificador del PAC: 'facturapi' | 'facturama'. */
    public function key(): string;

    /** Prepara el emisor. Facturapi crea organización; Facturama es no-op. Devuelve ['org_id'=>?string]. */
    public function setup(array $ctx): array;

    /** Sube el CSD (.cer/.key en base64 + password) del emisor. */
    public function subirCsd(array $ctx, string $cerBase64, string $keyBase64, string $password): void;

    /** Timbra. Devuelve ['uuid'=>string, 'pac_id'=>string, 'xml'=>?string]. */
    public function crearFactura(array $ctx, array $invoice): array;

    public function descargarXml(array $ctx, string $pacId): string;

    public function descargarPdf(array $ctx, string $pacId): string;

    /** Cancela el CFDI. $motivo SAT (01-04); $uuidRelacionado requerido si motivo='01'. */
    public function cancelarFactura(array $ctx, string $pacId, string $motivo, ?string $uuidRelacionado): array;

    /** Verifica credenciales/conexión del emisor. */
    public function test(array $ctx): bool;
}
