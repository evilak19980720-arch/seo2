<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Game sites built from a section pool: the chosen structure per site, the
 * features a site really has (accounts, payments), verifiable licence and
 * certification facts, and the site data (games, providers, promotions,
 * events, notices, news) that fills lists and detail pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('structure')->nullable()->after('design_config');
            $table->boolean('has_accounts')->default(false)->after('reference_rewrite_content');
            $table->boolean('has_payments')->default(false)->after('has_accounts');
        });
        Schema::table('brand_profiles', function (Blueprint $table) {
            $table->text('license_info')->nullable();
            $table->text('certification_info')->nullable();
        });
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('collection', 20);
            $table->string('slug', 120);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('image')->nullable();
            $table->json('data')->nullable();
            $table->json('sections')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->string('source', 20)->default('ai');
            $table->timestamps();
            $table->unique(['site_id', 'collection', 'slug']);
            $table->index(['site_id', 'collection', 'sort']);
        });

        // Existing game sites keep the fixed page set they were built with.
        DB::table('sites')->where('site_type', 'game')->update(['site_type' => 'game_basic']);
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
        Schema::table('brand_profiles', fn (Blueprint $table) => $table->dropColumn(['license_info', 'certification_info']));
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['structure', 'has_accounts', 'has_payments']));
    }
};
