<?php

use Database\Seeders\PromptSeeder;
use Illuminate\Database\Migrations\Migration;

/** Design prompt v5: phones first (layout choices, mobile bar, mobile-first custom CSS). */
return new class extends Migration
{
    public function up(): void
    {
        (new PromptSeeder)->run();
    }

    public function down(): void {}
};
