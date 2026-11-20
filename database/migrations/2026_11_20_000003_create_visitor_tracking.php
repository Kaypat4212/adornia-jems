<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_settings', function (Blueprint $table): void {
            $table->id();
            $table->text('content');
            $table->timestamps();
        });
        Schema::create('visitors', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('ip_hash', 64);
            $table->text('ip_address');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path')->default('/');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
        });
        Schema::create('visitor_identities', function (Blueprint $table): void {
            $table->string('identity', 80)->primary();
            $table->timestamp('created_at');
        });
        Schema::create('visitor_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visitor_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->string('telegram_status')->default('disabled');
            $table->text('last_error')->nullable();
            $table->timestamp('telegram_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_alerts');
        Schema::dropIfExists('visitor_identities');
        Schema::dropIfExists('visitors');
        Schema::dropIfExists('visitor_settings');
    }
};
