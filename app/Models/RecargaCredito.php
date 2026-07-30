<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecargaCredito extends Model
{
    protected $table = 'recargas_credito';

    protected $fillable = ['empresa_id', 'pesos', 'costo_timbre', 'factura_id', 'notas'];

    protected $casts = [
        'pesos'        => 'float',
        'costo_timbre' => 'float',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function factura()
    {
        return $this->belongsTo(SuscripcionFactura::class, 'factura_id');
    }
}
