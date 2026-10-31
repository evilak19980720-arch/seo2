<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained()->restrictOnDelete();
            $table->string('site_type', 40);
            $table->string('product_name');
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->string('locale', 20)->default('pt-BR');
            $table->string('target_country', 2)->default('BR');
            $table->string('title')->nullable();
            $table->string('description', 320)->nullable();
            $table->string('reference_url')->nullable();
            $table->json('design_config')->nullable();
            $table->unsignedInteger('design_version')->default(0);
            $table->text('custom_css')->nullable();
            $table->string('status', 20)->default('draft'); // draft | generating | preview | live | paused | failed | deleted
            $table->boolean('auto_publish_paused')->default(false);
            $table->string('pause_reason')->nullable();
            $table->unsignedInteger('indexed_pages')->default(0);
            $table->unsignedInteger('impressions_7d')->default(0);
            $table->unsignedInteger('clicks_7d')->default(0);
            $table->string('ga4_property_id')->nullable();
            $table->string('ga4_measurement_id', 30)->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('site_relations', function (Blueprint $table) {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_site_id')->constrained('sites')->cascadeOnDelete();
            $table->primary(['site_id', 'related_site_id']);
        });

        Schema::create('design_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('config');
            $table->text('custom_css')->nullable();
            $table->string('source', 30)->default('ai'); // ai | manual | rollback
            $table->timestamps();
            $table->unique(['site_id', 'version']);
        });

        Schema::create('brand_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('product_name')->nullable();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->json('selling_points')->nullable();
            $table->json('specs')->nullable();
            $table->text('target_audience')->nullable();
            $table->text('use_cases')->nullable();
            $table->json('media')->nullable();
            $table->json('cta_links')->nullable();
            $table->json('faq')->nullable();
            $table->string('company_name')->nullable();
            $table->string('company_email')->nullable();
            $table->string('company_address')->nullable();
            $table->unsignedTinyInteger('completeness')->default(0);
            $table->timestamps();
        });

        Schema::create('generation_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->default('full'); // full | rebuild | design
            $table->string('status', 20)->default('queued'); // queued | running | succeeded | failed
            $table->string('step')->nullable();
            $table->unsignedInteger('pages_total')->default(0);
            $table->unsignedInteger('pages_done')->default(0);
            $table->json('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_tasks');
        Schema::dropIfExists('brand_profiles');
        Schema::dropIfExists('design_versions');
        Schema::dropIfExists('site_relations');
        Schema::dropIfExists('sites');
    }
};
