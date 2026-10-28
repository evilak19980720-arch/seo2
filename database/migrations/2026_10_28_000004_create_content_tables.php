<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->string('intent', 30)->default('informational'); // informational | navigational | commercial | transactional
            $table->string('target_path')->nullable();
            $table->string('status', 20)->default('pending'); // pending | written | has_impressions
            $table->string('source', 20)->default('ai'); // ai | gsc | manual
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'keyword']);
            $table->index(['site_id', 'status']);
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('slug');
            $table->string('title');
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->json('content')->nullable();
            $table->boolean('noindex')->default(false);
            $table->string('index_verdict', 30)->nullable();
            $table->timestamp('index_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
            $table->unique(['site_id', 'type']);
        });

        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('site_type', 40)->nullable();
            $table->string('locale', 20)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->text('system')->nullable();
            $table->longText('template');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['type', 'site_type', 'locale', 'is_active']);
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prompt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('type', 30); // guide | update | faq | use_case
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('featured_image')->nullable();
            $table->string('featured_image_alt')->nullable();
            $table->string('author')->nullable();
            $table->longText('body')->nullable();
            $table->string('status', 20)->default('queued'); // queued | published | blocked | noindex | deleted
            $table->text('block_reason')->nullable();
            $table->decimal('similarity_score', 5, 4)->nullable();
            $table->json('fingerprint')->nullable();
            $table->unsignedInteger('impressions_total')->default(0);
            $table->unsignedInteger('impressions_30d')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('noindexed_at')->nullable();
            $table->string('index_verdict', 30)->nullable();
            $table->timestamp('index_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
        Schema::dropIfExists('prompts');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('keywords');
    }
};
