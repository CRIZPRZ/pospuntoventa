<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoMeliKit extends Model
{
    protected $table = 'producto_meli_kits';

    protected $fillable = ['producto_meli_id', 'producto_id', 'cantidad'];

    public function productoMeli(): BelongsTo
    {
        return $this->belongsTo(ProductoMeli::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
