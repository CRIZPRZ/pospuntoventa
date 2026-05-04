<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'nombre',
        'email',
        'telefono',
        'rfc',
        'direccion',
        'limite_credito',
        'saldo_credito',
        'activo',
    ];

    protected $casts = [
        'limite_credito' => 'decimal:2',
        'saldo_credito' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function ventas()
    {
        return $this->hasMany(Venta::class);
    }

    public function abonos()
    {
        return $this->hasMany(Abono::class);
    }

    public function creditoDisponible()
    {
        return max(0, (float) $this->limite_credito - (float) $this->saldo_credito);
    }
}
