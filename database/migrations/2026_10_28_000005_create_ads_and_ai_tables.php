<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('code');
            $table->string('position', 20)->default('top'); // top | bottom
            $table->unsignedSmallInteger('desktop_width')->nullable();
            $table->unsignedSmallInteger('desktop_height')->nullable();
            $table->unsignedSmallInteger('mobile_width')->nullable();
            $table->unsignedSmallInteger('mobile_height')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ad_site', function (Blueprint $table) {
            $table->foreignId('ad_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->primary(['ad_id', 'site_id']);
        });

        Schema::create('ad_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20);
            $table->json('changes')->nullable();
            $table->timestamps();
        });

        Schema::create('ad_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('estimated_clicks')->default(0);
            $table->unique(['ad_id', 'site_id', 'date']);
        });

        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver', 30)->default('openai'); // openai (compatible) | anthropic
            $table->string('base_url');
            $table->text('api_key');
            $table->string('model');
            $table->string('embedding_model')->nullable();
            $table->boolean('supports_vision')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('max_concurrency')->default(4);
            $table->decimal('monthly_budget', 10, 2)->nullable();
            $table->decimal('input_price_per_million', 10, 4)->default(0);
            $table->decimal('output_price_per_million', 10, 4)->default(0);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 40);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 10, 4)->default(0);
            $table->boolean('success')->default(true);
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index(['ai_provider_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('ai_providers');
        Schema::dropIfExists('ad_stats');
        Schema::dropIfExists('ad_change_logs');
        Schema::dropIfExists('ad_site');
        Schema::dropIfExists('ads');
    }
};
