<?php

use Database\Seeders\PromptSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Image generation model per provider (article illustrations), and the article prompts that ask for them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->string('image_model')->nullable()->after('embedding_model');
        });
        (new PromptSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->dropColumn('image_model');
        });
    }
};
