<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MySQL TIMESTAMP stops at 2038; Cloudflare Origin CA certificates last 15 years. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dateTime('certificate_expires_at')->nullable()->change();
        });
    }

    public function down(): void {}
};
