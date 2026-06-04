<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Camara extends Model
{
    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'nombre',
        'tipo',
        'url_rtsp',
        'usuario',
        'password',
        'url_stream',
        'aws_stream_name',
        'aws_region',
        'activo',
        'orden',
        'status',
        'last_error',
        'last_checked',
    ];

    protected $casts = [
        'activo'       => 'boolean',
        'last_checked' => 'datetime',
    ];

    protected $hidden = ['password'];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function getRtspConCredencialesAttribute(): ?string
    {
        if (!$this->url_rtsp) return null;

        if ($this->usuario) {
            $parsed = parse_url($this->url_rtsp);
            $scheme = $parsed['scheme'] ?? 'rtsp';
            $host   = $parsed['host'] ?? '';
            $port   = isset($parsed['port']) ? ":{$parsed['port']}" : '';
            $path   = $parsed['path'] ?? '';
            $creds  = urlencode($this->usuario) . ':' . urlencode($this->password ?? '') . '@';
            return "{$scheme}://{$creds}{$host}{$port}{$path}";
        }

        return $this->url_rtsp;
    }
}
