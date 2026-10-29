<?php

use Database\Seeders\PromptSeeder;
use Illuminate\Database\Migrations\Migration;

/** Prompts forbid remarks about the sources ("the brand profile does not include…"); thin detail pages get a second attempt. */
return new class extends Migration
{
    public function up(): void
    {
        (new PromptSeeder)->run();
    }

    public function down(): void {}
};
