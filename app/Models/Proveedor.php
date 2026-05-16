<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    use SoftDeletes;

    protected $table = 'proveedores';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'nombre', 'contacto', 'telefono', 'email', 'rfc', 'notas', 'activo',
    ];

    protected $casts = ['activo' => 'boolean'];

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

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }
}
