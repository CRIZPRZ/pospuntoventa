<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimbreConsumo extends Model
{
    protected $table = 'timbres_consumo';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'venta_id', 'pac', 'uuid',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
