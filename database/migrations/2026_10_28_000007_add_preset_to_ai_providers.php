<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->string('preset', 30)->nullable()->after('name');
            $table->json('options')->nullable()->after('base_url'); // e.g. azure api_version
            $table->text('api_key')->nullable()->change(); // local servers need none
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->dropColumn(['preset', 'options']);
        });
    }
};
