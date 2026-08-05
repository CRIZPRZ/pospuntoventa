<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class License extends Model
{
    protected $fillable = [
        'empresa_id',
        'owner_user_id',
        'license_key',
        'status',
        'issued_at',
        'valid_until',
        'grace_until',
        'last_validated_at',
        'max_devices',
        'plan_snapshot',
        'notes',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'valid_until' => 'datetime',
        'grace_until' => 'datetime',
        'last_validated_at' => 'datetime',
        'plan_snapshot' => 'array',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(LicenseDevice::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
