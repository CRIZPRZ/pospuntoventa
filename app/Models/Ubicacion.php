<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ubicacion extends Model
{
    protected $table = 'ubicaciones';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'descripcion', 'codigo', 'ubicacion', 'precio', 'cantidad', 'activo',
    ];

    protected $casts = [
        'activo'   => 'boolean',
        'precio'   => 'decimal:2',
        'cantidad' => 'integer',
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
            if (!$model->sucursal_id && app()->bound('sucursal_id')) {
                $model->sucursal_id = app('sucursal_id');
            }
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
