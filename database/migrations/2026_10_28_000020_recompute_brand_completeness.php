<?php

use App\Models\BrandProfile;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** The score now counts only name, tagline and intro. */
    public function up(): void
    {
        BrandProfile::query()->each(fn (BrandProfile $profile) => $profile->forceFill(['completeness' => $profile->computeCompleteness()])->saveQuietly());
    }
};
