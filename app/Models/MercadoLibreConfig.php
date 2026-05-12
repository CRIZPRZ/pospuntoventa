<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MercadoLibreConfig extends Model
{
    protected $table = 'mercado_libre_config';

    protected $fillable = [
        'empresa_id', 'client_id', 'client_secret', 'access_token', 'refresh_token',
        'token_expires_at', 'seller_id', 'seller_name', 'site_id',
        'callback_url', 'active', 'auto_sync_stock', 'auto_publish', 'sandbox_mode', 'test_user',
        'test_access_token', 'test_refresh_token', 'test_token_expires_at',
    ];

    protected $casts = [
        'token_expires_at'      => 'datetime',
        'test_token_expires_at' => 'datetime',
        'active'                => 'boolean',
        'auto_sync_stock'       => 'boolean',
        'auto_publish'          => 'boolean',
        'sandbox_mode'          => 'boolean',
        'test_user'             => 'json',
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

    public static function getActive(): ?self
    {
        return static::where('active', true)->first();
    }

    public function hasValidToken(): bool
    {
        return $this->access_token && $this->token_expires_at && $this->token_expires_at->isFuture();
    }

    public function isTokenExpiringSoon(): bool
    {
        if (!$this->token_expires_at) return true;
        return $this->token_expires_at->diffInMinutes(now()) < 30;
    }

    public function isTestTokenExpiringSoon(): bool
    {
        if (!$this->test_token_expires_at) return true;
        return $this->test_token_expires_at->diffInMinutes(now()) < 30;
    }

    public function hasValidTestToken(): bool
    {
        return $this->test_access_token && $this->test_token_expires_at && $this->test_token_expires_at->isFuture();
    }
}
