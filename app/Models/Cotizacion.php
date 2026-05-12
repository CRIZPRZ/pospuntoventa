<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cotizacion extends Model
{
    use SoftDeletes;

    protected $table = 'cotizaciones';

    protected $fillable = [
        'empresa_id', 'folio', 'cliente_id', 'nombre_cliente', 'email_cliente', 'vendedor_id',
        'fecha', 'fecha_vencimiento', 'status',
        'subtotal', 'descuento', 'impuesto_pct', 'total',
        'notas', 'venta_id', 'pedido_id',
    ];

    protected $casts = [
        'fecha'            => 'date',
        'fecha_vencimiento' => 'date',
        'subtotal'         => 'decimal:2',
        'descuento'        => 'decimal:2',
        'impuesto_pct'     => 'decimal:2',
        'total'            => 'decimal:2',
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
        return $this->hasMany(CotizacionItem::class);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public static function generarFolio(): string
    {
        $empresaId = app()->bound('tenant_id') ? app('tenant_id') : null;

        $ultimo = static::withoutGlobalScope('tenant')
            ->withTrashed()
            ->where('empresa_id', $empresaId)
            ->where('folio', 'like', 'COT-%')
            ->orderByDesc('id')
            ->value('folio');

        $siguiente = 1;
        if ($ultimo) {
            $numero = (int) str_replace('COT-', '', $ultimo);
            $siguiente = $numero + 1;
        }

        return 'COT-' . str_pad($siguiente, 4, '0', STR_PAD_LEFT);
    }

    public function calcularTotales(): void
    {
        $this->loadMissing('items');
        $subtotalBruto  = $this->items->sum('subtotal');
        $descuentoMonto = $subtotalBruto * ($this->descuento / 100);
        $base           = $subtotalBruto - $descuentoMonto;
        $impuestoMonto  = $base * ($this->impuesto_pct / 100);
        $this->subtotal = round($base, 2);
        $this->total    = round($base + $impuestoMonto, 2);
        $this->save();
    }
}
