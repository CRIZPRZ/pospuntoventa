<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoMeli extends Model
{
    protected $table = 'producto_meli';

    protected $fillable = [
        'producto_id', 'meli_item_id', 'status', 'listing_type_id',
        'price_usd', 'last_sync_at', 'published_at',
    ];

    protected $casts = [
        'price_usd' => 'decimal:2',
        'last_sync_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
