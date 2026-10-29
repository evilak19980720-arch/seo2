<?php

use Database\Seeders\PromptSeeder;
use Illuminate\Database\Migrations\Migration;

/** AI quality check prompt v2: issues written in Chinese for the admin log; existing prompts stay untouched. */
return new class extends Migration
{
    public function up(): void
    {
        (new PromptSeeder)->run();
    }

    public function down(): void {}
};
