<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->string('country', 100)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('country_lookup_hash', 64)->nullable();
            $table->string('device_type', 16)->default('Unknown');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropColumn(['country', 'country_code', 'country_lookup_hash', 'device_type']);
        });
    }
};
