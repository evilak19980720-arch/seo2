<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_profiles', function (Blueprint $table) {
            $table->json('localized')->nullable(); // display fields in the site language
            $table->string('localized_hash', 32)->nullable();
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->json('reference_images')->nullable()->after('reference_url');
        });
    }

    public function down(): void
    {
        Schema::table('brand_profiles', fn (Blueprint $table) => $table->dropColumn(['localized', 'localized_hash']));
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('reference_images'));
    }
};
