<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuperAdminConfig extends Model
{
    protected $table = 'superadmin_config';
    protected $fillable = ['key', 'value'];

    // Helpers estáticos para leer/escribir como key-value store
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();
        if (! $row) return $default;
        $decoded = json_decode($row->value, true);
        return $decoded !== null ? $decoded : $row->value;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_string($value) ? $value : json_encode($value)]
        );
    }

    public static function getAll(): array
    {
        return static::all()->mapWithKeys(fn($row) => [
            $row->key => json_decode($row->value, true) ?? $row->value,
        ])->toArray();
    }
}
