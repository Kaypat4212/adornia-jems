<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table): void {
            $table->string('transport')->nullable();
            $table->string('message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['transport', 'message_id']);
        });
    }
};
