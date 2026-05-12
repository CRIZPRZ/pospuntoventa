<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Producto extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'empresa_id', 'categoria_id', 'proveedor_id', 'nombre', 'descripcion', 'codigo',
        'codigo_barras', 'precio', 'precio_compra', 'stock', 'stock_minimo', 'unidad',
        'imagen', 'imagenes', 'activo', 'disponible_ml', 'control_stock',
    ];

    protected $casts = [
        'precio'        => 'decimal:2',
        'precio_compra' => 'decimal:2',
        'activo'        => 'boolean',
        'control_stock' => 'boolean',
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

    public function getImagenAttribute(?string $value): ?string
    {
        if (!$value) return null;
        return $this->toFullUrl($value);
    }

    public function setImagenAttribute(?string $value): void
    {
        $this->attributes['imagen'] = $value ? $this->toRelativePath($value) : null;
    }

    public function getImagenesAttribute(mixed $value): array
    {
        $paths = is_array($value) ? $value : (json_decode($value ?? '[]', true) ?? []);
        return array_values(array_map(fn($p) => $this->toFullUrl($p), array_filter($paths)));
    }

    public function setImagenesAttribute(mixed $value): void
    {
        $paths = array_values(array_filter(array_map(
            fn($p) => $p ? $this->toRelativePath($p) : null,
            (array) $value
        )));
        $this->attributes['imagenes'] = json_encode($paths);
    }

    private function toFullUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        // Strip any accidental /storage/ prefix before generating URL
        $clean = ltrim(preg_replace('#^/storage/#', '', $path), '/');
        // Always use 'public' disk — images are stored with store('...', 'public')
        $url = Storage::disk('public')->url($clean);
        // Fallback: if still relative, prefix with APP_URL manually
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $base = rtrim(config('app.url'), '/');
            $url = $base . '/' . ltrim($url, '/');
        }
        return $url;
    }

    private function toRelativePath(string $url): string
    {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return $url;
        }
        $path = parse_url($url, PHP_URL_PATH) ?? $url;
        return ltrim(preg_replace('#^/storage/#', '', $path), '/');
    }

    public function getCostoAttribute()
    {
        return $this->precio_compra;
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function ventaItems(): HasMany
    {
        return $this->hasMany(VentaItem::class);
    }

    public function mercadoLibre(): HasMany
    {
        return $this->hasMany(ProductoMeli::class);
    }
}
