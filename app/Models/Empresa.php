<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Empresa extends Model
{
    protected $fillable = ['nombre', 'slug', 'email', 'status'];

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
}
