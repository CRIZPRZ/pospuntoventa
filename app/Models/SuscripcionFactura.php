<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuscripcionFactura extends Model
{
    protected $fillable = [
        'empresa_id', 'stripe_invoice_id',
        'cfdi_uuid', 'cfdi_facturapi_id', 'cfdi_xml', 'cfdi_status',
        'receptor', 'monto', 'moneda', 'concepto',
    ];

    protected $casts = ['receptor' => 'array'];

    public function empresa() { return $this->belongsTo(Empresa::class); }
}
