<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('status', 20)->default('available'); // available | used
            $table->string('ip', 45)->nullable();
            $table->string('source', 20)->default('new'); // new | expired
            $table->string('origin_topic')->nullable();
            $table->text('history_notes')->nullable();
            $table->string('cf_zone_id')->nullable();
            $table->boolean('cf_proxied')->default(false);
            $table->string('cf_ssl_status', 30)->nullable();
            $table->string('gsc_status', 30)->default('unverified'); // unverified | pending | verified | failed
            $table->string('gsc_verification_token')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
