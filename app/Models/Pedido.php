<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'folio', 'cotizacion_id', 'cliente_id', 'nombre_cliente',
        'email_cliente', 'vendedor_id', 'fecha', 'fecha_entrega', 'status',
        'subtotal', 'descuento', 'impuesto_pct', 'total', 'notas',
    ];

    protected $casts = [
        'fecha'         => 'date',
        'fecha_entrega' => 'date',
        'subtotal'      => 'decimal:2',
        'descuento'     => 'decimal:2',
        'impuesto_pct'  => 'decimal:2',
        'total'         => 'decimal:2',
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
            if (!$model->folio) {
                $model->folio = static::generarFolio($model->empresa_id);
            }
        });
    }

    public static function generarFolio(?int $empresaId = null): string
    {
        $id = $empresaId ?? (app()->bound('tenant_id') ? app('tenant_id') : null);

        $ultimo = static::withoutGlobalScope('tenant')
            ->withTrashed()
            ->where('empresa_id', $id)
            ->where('folio', 'like', 'PED-%')
            ->orderByDesc('id')
            ->value('folio');

        $siguiente = 1;
        if ($ultimo) {
            $numero = (int) str_replace('PED-', '', $ultimo);
            $siguiente = $numero + 1;
        }

        return 'PED-' . str_pad($siguiente, 4, '0', STR_PAD_LEFT);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }
}
