<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains now come from Cloudflare: no new/expired distinction, binding is
 * derived from the site that uses the domain. Sites no longer link to each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['source', 'origin_topic', 'history_notes', 'status']);
        });
        Schema::table('domains', function (Blueprint $table) {
            $table->string('cf_status', 20)->nullable()->after('cf_zone_id'); // active | pending | missing
            $table->json('name_servers')->nullable()->after('cf_status');
            $table->timestamp('cf_synced_at')->nullable()->after('name_servers');
        });

        Schema::dropIfExists('site_relations');
    }

    public function down(): void
    {
        Schema::create('site_relations', function (Blueprint $table) {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_site_id')->constrained('sites')->cascadeOnDelete();
            $table->primary(['site_id', 'related_site_id']);
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['cf_status', 'name_servers', 'cf_synced_at']);
            $table->string('status', 20)->default('available');
            $table->string('source', 20)->default('new');
            $table->string('origin_topic')->nullable();
            $table->text('history_notes')->nullable();
        });
    }
};
