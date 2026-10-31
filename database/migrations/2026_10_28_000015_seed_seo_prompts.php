<?php

use Database\Seeders\PromptSeeder;
use Illuminate\Database\Migrations\Migration;

/** Installs revised default prompts (page content v5, site meta v2: SEO rules) (new versions only; edited prompts stay untouched). */
return new class extends Migration
{
    public function up(): void
    {
        (new PromptSeeder)->run();
    }

    public function down(): void {}
};
