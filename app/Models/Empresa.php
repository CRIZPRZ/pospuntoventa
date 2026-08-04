<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Empresa extends Model
{
    protected $fillable = [
        'nombre', 'slug', 'email', 'status',
        'plan_id', 'plan_vigente_hasta', 'plan_estado',
        'plan_precio_pactado', 'stripe_customer_id', 'stripe_subscription_id',
        'datos_facturacion', 'timbres_extra', 'pac_provider', 'whatsapp_provider',
        'credito_timbres', 'costo_timbre',
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

    public function license(): HasOne
    {
        return $this->hasOne(License::class);
    }

    public function modulosActivos(): array
    {
        return $this->modulos()->where('activo', true)->pluck('modulo_key')->toArray();
    }

    public function trialVigente(): bool
    {
        return in_array($this->plan_estado ?? 'sin_plan', ['trial', 'sin_plan'], true)
            && (bool) $this->plan_vigente_hasta?->isFuture();
    }

    public function accesoSistemaVigente(): bool
    {
        $estado = $this->plan_estado ?? 'sin_plan';

        if ($estado === 'activo') {
            return $this->planVigente();
        }

        return $this->trialVigente();
    }

    public function limiteSucursales(): int
    {
        if ($this->trialVigente()) {
            return 1;
        }

        if (($this->plan_estado ?? null) === 'activo' && $this->plan) {
            return (int) $this->plan->max_sucursales;
        }

        return 0;
    }

    public function limiteUsuarios(): int
    {
        if ($this->trialVigente()) {
            return 1;
        }

        if (($this->plan_estado ?? null) === 'activo' && $this->plan) {
            return (int) $this->plan->max_usuarios;
        }

        return 0;
    }

    public function planVigente(): bool
    {
        if (! $this->plan_id) return false;
        if (! $this->plan_vigente_hasta) return true; // sin fecha = siempre vigente
        return $this->plan_vigente_hasta->isFuture();
    }
}
