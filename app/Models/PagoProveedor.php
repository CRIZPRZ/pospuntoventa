<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PagoProveedor extends Model
{
    protected $table = 'pagos_proveedores';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'proveedor_id', 'concepto', 'monto', 'metodo_pago', 'referencia', 'notas', 'user_id',
    ];

    protected $casts = ['monto' => 'decimal:2'];

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

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
