<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'empresa_id', 'categoria_id', 'proveedor_id', 'nombre', 'descripcion', 'codigo',
        'codigo_barras', 'precio', 'precio_compra', 'stock', 'stock_minimo', 'unidad',
        'imagen', 'imagenes', 'activo', 'disponible_ml', 'control_stock',
    ];

    protected $casts = [
        'precio'        => 'decimal:2',
        'precio_compra' => 'decimal:2',
        'imagenes'      => 'array',
        'activo'        => 'boolean',
        'control_stock' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (app()->bound('tenant_id')) {
                $builder->where('empresa_id', app('tenant_id'));
            }
        });

        static::creating(function (self $model) {
            if (!$model->empresa_id && app()->bound('tenant_id')) {
                $model->empresa_id = app('tenant_id');
            }
        });
    }

    public function getCostoAttribute()
    {
        return $this->precio_compra;
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
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
