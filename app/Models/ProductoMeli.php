<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function kits(): HasMany
    {
        return $this->hasMany(ProductoMeliKit::class);
    }

    public function esKit(): bool
    {
        return $this->kits()->exists();
    }

    public function stockDisponible(): int
    {
        if ($this->esKit()) {
            $min = null;
            foreach ($this->kits()->with('producto')->get() as $comp) {
                if (!$comp->producto || $comp->cantidad <= 0) continue;
                $disponible = (int) floor($comp->producto->stock / $comp->cantidad);
                if ($min === null || $disponible < $min) {
                    $min = $disponible;
                }
            }
            return max(0, $min ?? 0);
        }

        return max(0, $this->producto?->stock ?? 0);
    }
}
