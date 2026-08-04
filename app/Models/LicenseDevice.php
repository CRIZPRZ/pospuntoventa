<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseDevice extends Model
{
    protected $fillable = [
        'license_id',
        'device_uuid',
        'device_name',
        'fingerprint',
        'platform',
        'app_version',
        'last_seen_at',
        'last_token_issued_at',
        'revoked_at',
        'is_active',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_token_issued_at' => 'datetime',
        'revoked_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
