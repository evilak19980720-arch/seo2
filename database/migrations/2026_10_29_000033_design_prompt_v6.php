<?php

use Database\Seeders\PromptSeeder;
use Illuminate\Database\Migrations\Migration;

/** Design prompt v6: the platform assigns each site's look; the model keeps colours, sections and header behaviour. */
return new class extends Migration
{
    public function up(): void
    {
        (new PromptSeeder)->run();
    }

    public function down(): void {}
};
