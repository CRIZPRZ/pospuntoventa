<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Empresa extends Model
{
    protected $fillable = [
        'nombre', 'slug', 'email', 'status',
        'plan_id', 'plan_vigente_hasta', 'plan_estado',
        'plan_precio_pactado', 'stripe_customer_id', 'stripe_subscription_id',
        'datos_facturacion', 'timbres_extra',
    ];

    protected $casts = [
        'plan_vigente_hasta'  => 'datetime',
        'datos_facturacion'   => 'array',
    ];

    public static function generarSlug(string $nombre): string
    {
        $base = Str::slug($nombre);
        $slug = $base;
        $i = 1;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function modulos(): HasMany
    {
        return $this->hasMany(EmpresaModulo::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function modulosActivos(): array
    {
        return $this->modulos()->where('activo', true)->pluck('modulo_key')->toArray();
    }

    public function planVigente(): bool
    {
        if (! $this->plan_id) return false;
        if (! $this->plan_vigente_hasta) return true; // sin fecha = siempre vigente
        return $this->plan_vigente_hasta->isFuture();
    }
}
