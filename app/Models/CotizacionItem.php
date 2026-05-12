<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotizacionItem extends Model
{
    protected $table = 'cotizacion_items';
    protected $fillable = [
        'cotizacion_id', 'producto_id', 'descripcion',
        'cantidad', 'precio_unitario', 'descuento', 'subtotal',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'descuento' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public static function calcularSubtotal(float $cantidad, float $precioUnitario, float $descuento = 0): float
    {
        return round($cantidad * $precioUnitario * (1 - $descuento / 100), 2);
    }
}
