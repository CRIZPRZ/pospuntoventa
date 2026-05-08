<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'categoria_id', 'proveedor_id', 'nombre', 'descripcion', 'codigo', 'codigo_barras',
        'precio', 'precio_compra', 'stock', 'stock_minimo', 'unidad', 'imagen', 'imagenes',
        'activo', 'disponible_ml', 'control_stock',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'precio_compra' => 'decimal:2',
        'imagenes' => 'array',
        'activo' => 'boolean',
        'control_stock' => 'boolean',
    ];

    // Alias backwards-compat: código que use ->costo sigue funcionando
    public function getCostoAttribute()
    {
        return $this->precio_compra;
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function ventaItems(): HasMany
    {
        return $this->hasMany(VentaItem::class);
    }

    public function mercadoLibre(): HasMany
    {
        return $this->hasMany(ProductoMeli::class);
    }
}
