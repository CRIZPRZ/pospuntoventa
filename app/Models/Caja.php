<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caja extends Model
{
    protected $fillable = [
        'user_id', 'fondo_inicial', 'total_efectivo', 'total_tarjeta',
        'total_credito', 'total_ventas', 'efectivo_contado', 'diferencia', 'num_transacciones',
        'abierta_at', 'cerrada_at', 'cerrada_por', 'estado', 'observaciones',
    ];

    protected $casts = [
        'fondo_inicial' => 'decimal:2',
        'total_efectivo' => 'decimal:2',
        'total_tarjeta' => 'decimal:2',
        'total_credito' => 'decimal:2',
        'total_ventas' => 'decimal:2',
        'efectivo_contado' => 'decimal:2',
        'diferencia' => 'decimal:2',
        'abierta_at' => 'datetime',
        'cerrada_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }
}
