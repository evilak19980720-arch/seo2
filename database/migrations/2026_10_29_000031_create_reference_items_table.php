<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The reference site's pictures as collected: each card keeps its name, text, link and picture together. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('image'); // card | image
            $table->string('name', 200)->nullable();
            $table->text('text')->nullable();
            $table->text('detail')->nullable();
            $table->string('link', 1000)->nullable();
            $table->string('page_url', 1000)->nullable();
            $table->string('image_url', 1000);
            $table->char('image_hash', 32);
            $table->string('image_path')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'image_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_items');
    }
};
