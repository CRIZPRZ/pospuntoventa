<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $table = 'planes';

    protected $fillable = [
        'nombre', 'descripcion', 'precio_mensual',
        'max_sucursales', 'max_usuarios', 'modulos',
        'color', 'stripe_price_id', 'stripe_price_id_anual', 'activo', 'tipo',
    ];

    public function esGratis(): bool  { return $this->tipo === 'gratis'; }
    public function esStripe(): bool  { return $this->tipo === 'stripe'; }
    public function esManual(): bool  { return $this->tipo === 'manual'; }

    protected $casts = [
        'modulos'    => 'array',
        'activo'     => 'boolean',
        'precio_mensual' => 'decimal:2',
    ];

    public function empresas(): HasMany
    {
        return $this->hasMany(Empresa::class);
    }

    public function ilimitadoSucursales(): bool
    {
        return $this->max_sucursales === -1;
    }

    public function ilimitadoUsuarios(): bool
    {
        return $this->max_usuarios === -1;
    }
}
