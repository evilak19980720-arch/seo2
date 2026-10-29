<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoneUrl extends Model
{
    protected $fillable = ['host', 'path', 'to'];

    /** Where a moved page now lives, if it moved. */
    public static function redirectFor(string $host, string $path): ?string
    {
        return static::where('host', $host)->where('path', trim($path, '/'))->whereNotNull('to')->value('to');
    }

    public static function isGone(string $host, string $path): bool
    {
        $path = trim($path, '/');

        return static::where('host', $host)
            ->where(fn ($q) => $q->whereNull('path')->orWhere('path', $path))
            ->whereNull('to')
            ->exists();
    }
}
