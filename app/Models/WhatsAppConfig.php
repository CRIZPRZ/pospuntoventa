<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppConfig extends Model
{
    protected $table = 'whatsapp_configs';

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'provider',
        'business_name',
        'phone_number',
        'display_name',
        'connected_phone_number',
        'phone_number_id',
        'whatsapp_business_account_id',
        'access_token',
        'status',
        'last_test_at',
        'last_error',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'last_test_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function ($builder) {
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

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }
}
