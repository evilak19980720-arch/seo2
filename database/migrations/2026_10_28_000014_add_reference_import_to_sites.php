<?php

use Database\Seeders\PromptSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-site switches for importing reference images and rewriting reference content; page content prompt v4. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('reference_import_images')->default(false)->after('reference_images');
            $table->boolean('reference_rewrite_content')->default(false)->after('reference_import_images');
        });
        (new PromptSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['reference_import_images', 'reference_rewrite_content']);
        });
    }
};
