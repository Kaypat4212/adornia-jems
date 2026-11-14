<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('wallet_balance')->default(0);
            $table->boolean('must_change_password')->default(false);
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->string('provider');
            $table->string('reference')->unique();
            $table->string('provider_id')->nullable();
            $table->unique(['provider', 'provider_id']);
            $table->unsignedBigInteger('amount');
            $table->string('currency')->default('NGN');
            $table->string('status')->default('pending');
            $table->text('checkout_url')->nullable();
            $table->timestamps();
        });
        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('reference')->unique();
            $table->string('description');
            $table->timestamps();
        });
        Schema::create('homepage_sections', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->json('content');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('store_settings', function (Blueprint $table) {
            $table->id();
            $table->json('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
        Schema::dropIfExists('homepage_sections');
        Schema::dropIfExists('wallet_entries');
        Schema::dropIfExists('payments');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['wallet_balance', 'must_change_password']));
    }
};
