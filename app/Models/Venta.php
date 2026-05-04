<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venta extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'folio', 'user_id', 'caja_id', 'cliente_id',
        'subtotal', 'descuento', 'impuesto', 'total',
        'tipo_pago', 'estado', 'cancelada_por', 'notas',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'descuento' => 'decimal:2',
        'impuesto' => 'decimal:2',
        'total' => 'decimal:2',
    ];

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

    protected static function booted(): void
    {
        static::creating(function (Venta $venta) {
            $venta->folio = 'V-' . str_pad(static::withTrashed()->count() + 1, 6, '0', STR_PAD_LEFT);
        });
    }
}
