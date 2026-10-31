<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A removed page merged into another one sends its old address there (301) instead of answering 410. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gone_urls', function (Blueprint $table) {
            $table->string('to')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('gone_urls', function (Blueprint $table) {
            $table->dropColumn('to');
        });
    }
};
