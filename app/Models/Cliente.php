<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'nombre', 'email', 'telefono', 'rfc',
        'direccion', 'limite_credito', 'saldo_credito', 'activo',
        'codigo_postal', 'regimen_fiscal', 'uso_cfdi',
        'points_balance', 'lifetime_points',
    ];

    protected $casts = [
        'limite_credito'  => 'decimal:2',
        'saldo_credito'   => 'decimal:2',
        'activo'          => 'boolean',
        'points_balance'  => 'integer',
        'lifetime_points' => 'integer',
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

    public function ventas()
    {
        return $this->hasMany(Venta::class);
    }

    public function abonos()
    {
        return $this->hasMany(Abono::class);
    }

    public function loyaltyTransactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function creditoDisponible()
    {
        return max(0, (float) $this->limite_credito - (float) $this->saldo_credito);
    }
}
