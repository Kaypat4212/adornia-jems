<?php
namespace Tests\Feature;

use App\Models\{User, GatewaySetting, StoreSetting, RefundAccount, Payment, Order, Product};
use App\Services\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Storage, DB};
use Illuminate\Http\UploadedFile;
use Database\Factories\UserFactory;
use Tests\TestCase;

class PaymentSettingsAndProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        \App\Models\DeliveryRate::where('state', 'Lagos')->update(['active' => true, 'fee' => 0, 'pay_on_delivery' => true]);
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        return $user;
    }

    private function bank(): void
    {
        $setting = StoreSetting::findOrFail(1);
        $setting->update(['content' => $setting->content + ['manual_bank' => ['enabled' => true, 'bank_name' => 'Company Bank', 'account_name' => 'Adornia Jems', 'account_number' => '0123456789']]]);
    }

    public function test_gateway_keys_are_encrypted_not_redisplayed_and_password_protected(): void
    {
        $this->actingAs($this->admin());
        $data = ['mode' => 'test', 'enabled' => 1, 'current_password' => UserFactory::password(), 'keys' => ['test' => ['secret' => 'sk_test_private', 'public' => 'pk_test_public'], 'live' => ['secret' => 'sk_live_private']]];
        $this->put('/admin/payment-settings/paystack', array_replace($data, ['current_password' => 'wrong']))->assertSessionHasErrors();
        $this->assertDatabaseCount('gateway_settings', 0);
        $this->put('/admin/payment-settings/paystack', $data)->assertRedirect();
        $this->assertStringNotContainsString('sk_test_private', DB::table('gateway_settings')->value('credentials'));
        $this->get('/admin/payment-settings')->assertOk()->assertDontSee('sk_test_private')->assertDontSee('sk_live_private');
        $this->put('/admin/payment-settings/paystack', ['mode' => 'live', 'enabled' => 1, 'current_password' => UserFactory::password()])->assertRedirect();
        $this->assertEquals('sk_live_private', app(\App\Services\GatewayConfiguration::class)->credential('paystack'));
        $this->assertStringNotContainsString('sk_live_private', GatewaySetting::first()->toJson());
    }

    public function test_payment_created_in_test_mode_keeps_test_keys_after_live_switch(): void
    {
        $user = $this->admin();
        $setting = GatewaySetting::create(['provider' => 'paystack', 'enabled' => true, 'mode' => 'test', 'credentials' => ['test' => ['secret' => 'sk_test_private'], 'live' => ['secret' => 'sk_live_private']]]);
        $payment = Payment::create(['user_id' => $user->id, 'provider' => 'paystack', 'reference' => 'test-reference', 'amount' => 50000]);
        $setting->update(['mode' => 'live']);
        Http::fake(['*' => Http::response(['data' => ['status' => 'success', 'reference' => $payment->reference, 'amount' => 50000, 'currency' => 'NGN', 'id' => 123]])]);
        app(PaymentGateway::class)->verify($payment);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_private'));
        $this->assertEquals(0, $user->fresh()->wallet_balance);
        $this->assertEquals(50000, $user->fresh()->wallet_test_balance);
        $this->assertDatabaseHas('wallet_entries', ['mode' => 'test', 'amount' => 50000]);
    }

    public function test_manual_deposit_needs_admin_review_and_cannot_be_credited_twice(): void
    {
        Storage::fake('local');
        $this->bank();
        $user = User::factory()->create();
        $this->actingAs($user)->post('/wallet/deposit', ['amount' => 1000, 'provider' => 'manual'])->assertRedirect();
        $payment = Payment::firstOrFail();
        $this->get('/payments/'.$payment->reference.'/manual')->assertOk()->assertSee('0123456789');
        $this->post('/payments/'.$payment->reference.'/manual', ['transfer_reference' => 'bank-123', 'receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 transfer receipt')])->assertRedirect();
        $this->assertEquals('under_review', $payment->fresh()->status);
        $this->assertEquals(0, $user->fresh()->wallet_balance);
        $this->get('/admin/payments/'.$payment->id.'/receipt')->assertForbidden();
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/sales')->assertOk()->assertSee('Review transfer');
        $this->get('/admin/payments/'.$payment->id.'/receipt')->assertOk();
        $review = ['decision' => 'approve', 'bank_reference' => 'BANK-TXN-123', 'review_note' => 'Confirmed in company bank', 'current_password' => UserFactory::password()];
        $this->post('/admin/payments/'.$payment->id.'/review', $review)->assertRedirect();
        $this->post('/admin/payments/'.$payment->id.'/review', $review)->assertSessionHasErrors();
        $this->assertEquals(100000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_entries', 1);
        Storage::disk('local')->assertExists($payment->fresh()->receipt);
        $duplicate = Payment::create(['user_id' => $user->id, 'provider' => 'manual', 'reference' => 'duplicate-manual', 'amount' => 100000, 'status' => 'under_review']);
        $this->post('/admin/payments/'.$duplicate->id.'/review', $review)->assertSessionHasErrors('payment');
        $this->assertEquals(100000, $user->fresh()->wallet_balance);
        $this->assertEquals('under_review', $duplicate->fresh()->status);
    }

    public function test_manual_order_is_unpaid_until_admin_confirms_transfer(): void
    {
        Storage::fake('local');
        $this->bank();
        $this->actingAs(User::factory()->create())->withSession(['cart' => [1 => 1]])->post('/checkout', ['name' => 'Ada', 'phone' => '0801', 'address' => 'Home', 'city' => 'Lagos', 'state' => 'Lagos', 'delivery_rate_id' => \App\Models\DeliveryRate::where('state', 'Lagos')->value('id'), 'terms' => 1, 'payment_method' => 'manual'])->assertRedirect();
        $order = Order::firstOrFail();
        $payment = Payment::firstOrFail();
        $this->assertEquals('unpaid', $order->payment_status);
        $this->assertEquals('live', $payment->mode);
        $payment->update(['status' => 'under_review']);
        $this->actingAs($this->admin())->put('/admin/orders/'.$order->id, ['status' => 'confirmed'])->assertSessionHasErrors();
        $this->post('/admin/payments/'.$payment->id.'/review', ['decision' => 'approve', 'bank_reference' => 'BANK-TXN-123', 'review_note' => 'Bank confirmed', 'current_password' => UserFactory::password()])->assertRedirect();
        $this->assertEquals('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_refund_accounts_use_paystack_name_and_encrypt_number(): void
    {
        config(['payments.paystack.secret' => 'sk_test_mock']);
        Http::fake([
            'api.paystack.co/bank?*' => Http::response(['status' => true, 'data' => [['name' => 'Verified Bank', 'code' => '058']]]),
            'api.paystack.co/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_number' => '0123456789', 'account_name' => 'ADA VERIFIED']]),
        ]);
        $user = User::factory()->create();
        $this->actingAs($user)->get('/account/banks')->assertOk()->assertJsonPath('banks.0.code', '058');
        $this->post('/account/refund-accounts', ['bank_code' => '058', 'account_number' => '0123456789', 'account_name' => 'Spoofed', 'current_password' => UserFactory::password()])->assertRedirect();
        $account = RefundAccount::firstOrFail();
        $this->assertEquals('ADA VERIFIED', $account->account_name);
        $this->assertEquals('test', $account->verification_mode);
        $this->assertStringNotContainsString('0123456789', DB::table('refund_accounts')->value('account_number'));
        $this->get('/account')->assertOk()->assertSee('ADA VERIFIED')->assertDontSee('0123456789');
        $this->actingAs(User::factory()->create())->delete('/account/refund-accounts/'.$account->id, ['current_password' => UserFactory::password()])->assertForbidden();
        $this->actingAs($user)->delete('/account/refund-accounts/'.$account->id, ['current_password' => UserFactory::password()])->assertRedirect();
        $this->assertDatabaseCount('refund_accounts', 0);
    }

    public function test_failed_bank_verification_never_saves_an_account(): void
    {
        config(['payments.paystack.secret' => 'sk_test_mock']);
        Http::fake(['*' => Http::response(['status' => false], 422)]);
        $this->actingAs(User::factory()->create())->post('/account/refund-accounts', ['bank_code' => '058', 'account_number' => '0123456789', 'current_password' => UserFactory::password()])->assertSessionHasErrors('bank');
        $this->assertDatabaseCount('refund_accounts', 0);
    }

    public function test_profile_photo_can_be_uploaded_and_removed(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $image = UploadedFile::fake()->createWithContent('avatar.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg=='));
        $this->actingAs($user)->put('/account', ['name' => 'Ada', 'avatar_upload' => $image])->assertRedirect();
        $path = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($path);
        $this->put('/account', ['name' => 'Ada', 'remove_avatar' => 1])->assertRedirect();
        $this->assertNull($user->fresh()->avatar);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_customer_cannot_change_gateway_or_company_bank_settings(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/payment-settings')->assertForbidden();
        $this->put('/admin/payment-settings/paystack', [])->assertForbidden();
        $this->put('/admin/payment-settings/bank', [])->assertForbidden();
    }

    public function test_test_order_neither_changes_real_stock_nor_allows_fulfillment(): void
    {
        config(['payments.paystack.secret' => 'sk_test_mock']);
        $this->actingAs($this->admin())->withSession(['cart' => [1 => 1]])->post('/checkout', ['name' => 'Ada', 'phone' => '0801', 'address' => 'Home', 'city' => 'Lagos', 'state' => 'Lagos', 'delivery_rate_id' => \App\Models\DeliveryRate::where('state', 'Lagos')->value('id'), 'terms' => 1, 'payment_method' => 'paystack'])->assertRedirect();
        $order = Order::firstOrFail();
        $order->update(['payment_status' => 'paid']);
        $this->put('/admin/orders/'.$order->id, ['status' => 'confirmed'])->assertSessionHasErrors();
        $this->put('/admin/orders/'.$order->id, ['status' => 'cancelled'])->assertRedirect();
        $this->assertEquals(100, Product::find(1)->stock);
        $this->assertEquals(0, auth()->user()->fresh()->wallet_balance);
        $this->assertEquals(2800000, auth()->user()->fresh()->wallet_test_balance);
    }

    public function test_company_bank_survives_homepage_settings_update_and_admin_can_view_customer(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->put('/admin/payment-settings/bank', ['current_password' => UserFactory::password(), 'enabled' => 1, 'bank_name' => 'Company Bank', 'account_name' => 'Adornia Jems', 'account_number' => '0123456789'])->assertRedirect();
        $settings = StoreSetting::find(1)->content;
        unset($settings['manual_bank']);
        $this->put('/admin/settings', $settings)->assertRedirect();
        $this->assertEquals('0123456789', StoreSetting::find(1)->content['manual_bank']['account_number']);
        $this->get('/admin/customers/'.$user->id)->assertOk();
        $this->actingAs(User::factory()->create())->get('/admin/customers/'.$user->id)->assertForbidden();
    }

    public function test_wrong_mode_key_is_rejected_without_flashing_secrets(): void
    {
        $this->actingAs($this->admin())->put('/admin/payment-settings/flutterwave', ['current_password' => UserFactory::password(), 'mode' => 'test', 'enabled' => 1, 'keys' => ['test' => ['secret' => 'FLWSECK-live-secret']]])->assertSessionHasErrors('keys')->assertSessionMissing('_old_input.keys');
        $this->assertDatabaseCount('gateway_settings', 0);
    }
}
