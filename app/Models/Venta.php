<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venta extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'empresa_id', 'folio', 'user_id', 'caja_id', 'cliente_id',
        'subtotal', 'descuento', 'impuesto', 'total',
        'tipo_pago', 'estado', 'cancelada_por', 'notas',
    ];

    protected $casts = [
        'subtotal'  => 'decimal:2',
        'descuento' => 'decimal:2',
        'impuesto'  => 'decimal:2',
        'total'     => 'decimal:2',
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
            // Folio único por empresa
            $empresaId = $model->empresa_id;
            $count = static::withoutGlobalScope('tenant')
                ->withTrashed()
                ->where('empresa_id', $empresaId)
                ->count();
            $model->folio = 'V-' . str_pad($count + 1, 6, '0', STR_PAD_LEFT);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelada_por');
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function abonos(): HasMany
    {
        return $this->hasMany(Abono::class);
    }
}
