<?php

use Database\Seeders\PromptSeeder;
use Illuminate\Database\Migrations\Migration;

/** Installs page content v7 (new content blocks, sources-only sections) and site data v2 (game categories) (new versions only; edited prompts stay untouched). */
return new class extends Migration
{
    public function up(): void
    {
        (new PromptSeeder)->run();
    }

    public function down(): void {}
};
