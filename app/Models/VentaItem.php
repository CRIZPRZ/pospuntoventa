<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaItem extends Model
{
    protected $fillable = [
        'venta_id', 'producto_id', 'nombre_producto',
        'precio_unitario', 'costo_unitario', 'cantidad', 'descuento', 'subtotal',
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'costo_unitario' => 'decimal:2',
        'descuento' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
