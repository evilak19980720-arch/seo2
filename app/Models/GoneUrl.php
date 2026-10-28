<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoneUrl extends Model
{
    protected $fillable = ['host', 'path'];

    public static function isGone(string $host, string $path): bool
    {
        $path = trim($path, '/');

        return static::where('host', $host)
            ->where(fn ($q) => $q->whereNull('path')->orWhere('path', $path))
            ->exists();
    }
}
