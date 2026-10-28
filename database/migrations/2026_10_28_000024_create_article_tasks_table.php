<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per article generation or rewrite, showing which step it is on. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('new');
            $table->string('keyword')->nullable();
            $table->string('status', 20)->default('waiting')->index();
            $table->text('note')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_tasks');
    }
};
