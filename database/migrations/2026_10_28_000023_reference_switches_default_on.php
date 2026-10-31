<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** New sites import the reference images (to OSS) and rewrite its content by default. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('reference_import_images')->default(true)->change();
            $table->boolean('reference_rewrite_content')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('reference_import_images')->default(false)->change();
            $table->boolean('reference_rewrite_content')->default(false)->change();
        });
    }
};
