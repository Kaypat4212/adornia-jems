<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('delivery_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('state');
            $table->unsignedInteger('fee')->nullable();
            $table->boolean('active')->default(false);
            $table->boolean('pay_on_delivery')->default(false);
            $table->string('estimate')->nullable();
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_rate_id')->nullable();
            $table->string('delivery_zone')->nullable();
            $table->unsignedInteger('delivery_fee')->default(0);
        });
        Schema::create('email_settings', function (Blueprint $table) {
            $table->id();
            $table->text('content');
            $table->timestamps();
        });
        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('recipient');
            $table->string('subject');
            $table->text('message');
            $table->string('action_path')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
        Schema::dropIfExists('email_settings');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['delivery_rate_id', 'delivery_zone', 'delivery_fee']));
        Schema::dropIfExists('delivery_rates');
    }
};
