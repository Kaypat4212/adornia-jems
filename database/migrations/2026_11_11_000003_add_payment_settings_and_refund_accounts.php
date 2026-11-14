<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->string('mode')->default('test');
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable();
            $table->timestamps();
        });
        Schema::create('refund_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('bank_code');
            $table->string('bank_name');
            $table->text('account_number');
            $table->string('account_fingerprint');
            $table->string('account_name');
            $table->string('verification_mode');
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->unique(['user_id', 'account_fingerprint']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable();
            $table->bigInteger('wallet_test_balance')->default(0);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('mode')->default('test');
            $table->json('details')->nullable();
            $table->string('receipt')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
        });
        Schema::table('orders', fn (Blueprint $table) => $table->string('payment_mode')->default('live'));
        Schema::table('wallet_entries', fn (Blueprint $table) => $table->string('mode')->default('live'));
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['mode', 'details', 'receipt', 'review_note', 'reviewed_at']);
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('payment_mode'));
        Schema::table('wallet_entries', fn (Blueprint $table) => $table->dropColumn('mode'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['avatar', 'wallet_test_balance']));
        Schema::dropIfExists('refund_accounts');
        Schema::dropIfExists('gateway_settings');
    }
};
