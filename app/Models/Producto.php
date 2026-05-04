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
        'categoria_id', 'nombre', 'descripcion', 'codigo', 'codigo_barras',
        'precio', 'costo', 'stock', 'stock_minimo', 'unidad', 'imagen', 'imagenes',
        'activo', 'disponible_ml', 'control_stock',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'costo' => 'decimal:2',
        'imagenes' => 'array',
        'activo' => 'boolean',
        'control_stock' => 'boolean',
    ];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
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
