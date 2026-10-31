<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->longText('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();
        });

        Schema::create('search_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('ctr', 6, 4)->default(0);
            $table->decimal('position', 6, 2)->default(0);
            $table->unsignedInteger('indexed_pages')->nullable();
            $table->unique(['site_id', 'date']);
        });

        Schema::create('crawler_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('bot', 20); // googlebot | bingbot
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('fake_hits')->default(0);
            $table->unsignedInteger('errors_4xx')->default(0);
            $table->unsignedInteger('errors_5xx')->default(0);
            $table->unique(['site_id', 'date', 'bot']);
        });

        Schema::create('analytics_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->json('payload')->nullable();
            $table->unique(['site_id', 'date']);
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 40); // traffic_drop | index_drop | manual_action | certificate | ai_budget | generation_failed
            $table->string('level', 10)->default('warning');
            $table->text('message');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('gone_urls', function (Blueprint $table) {
            $table->id();
            $table->string('host');
            $table->string('path')->nullable(); // null = whole host gone
            $table->timestamps();
            $table->index(['host', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gone_urls');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('analytics_cache');
        Schema::dropIfExists('crawler_stats');
        Schema::dropIfExists('search_stats');
        Schema::dropIfExists('settings');
    }
};
