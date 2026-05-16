<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Sucursal extends Model
{
    protected $table = 'sucursales';
    protected $fillable = [
        'empresa_id', 'nombre', 'direccion', 'telefono', 'ciudad', 'es_principal', 'activo', 'modo_caja',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
        'activo'       => 'boolean',
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

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'usuario_sucursal')
                    ->withPivot('role_id')
                    ->withTimestamps();
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function cajas(): HasMany
    {
        return $this->hasMany(Caja::class);
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'producto_sucursal')
                    ->withPivot(['stock', 'stock_minimo', 'precio', 'precio_mayoreo', 'activo'])
                    ->withTimestamps();
    }
}
