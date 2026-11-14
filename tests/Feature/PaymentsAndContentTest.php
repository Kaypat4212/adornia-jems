<?php
namespace Tests\Feature;

use App\Models\{User, Payment, Order, Product, HomepageSection, WalletEntry};
use App\Services\{PaymentGateway, Wallet};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentsAndContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.default_mode' => 'live']);
        $this->seed();
        \App\Models\DeliveryRate::where('state', 'Lagos')->update(['active' => true, 'fee' => 0, 'pay_on_delivery' => true]);
        Http::preventStrayRequests();
        foreach (['paystack', 'flutterwave', 'kora'] as $provider) {
            config(["payments.$provider.secret" => 'test-secret']);
        }
    }

    public function test_all_gateways_initialize_using_correct_amount_units(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Http::fake([
            'api.paystack.co/*' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/test']]),
            'api.flutterwave.com/*' => Http::response(['data' => ['link' => 'https://checkout.flutterwave.com/test']]),
            'api.korapay.com/*' => Http::response(['data' => ['checkout_url' => 'https://checkout.korapay.com/test']]),
        ]);
        foreach (['paystack', 'flutterwave', 'kora'] as $provider) {
            $this->post('/wallet/deposit', ['amount' => 500, 'provider' => $provider])->assertRedirect();
            $payment = Payment::where('provider', $provider)->firstOrFail();
            $this->get('/payments/'.$payment->reference.'/start')->assertRedirect();
            $this->assertEquals(50000, $payment->amount);
            Http::assertSent(fn ($request) => $request['amount'] === ($provider === 'paystack' ? 50000 : 500) && str_contains($request->url(), match ($provider) {'paystack' => 'paystack.co', 'flutterwave' => 'flutterwave.com', 'kora' => 'korapay.com'}));
        }
        $this->assertEquals(0, $user->fresh()->wallet_balance);
    }

    public function test_each_gateway_verification_credits_wallet_exactly_once(): void
    {
        $user = User::factory()->create();
        foreach (['paystack', 'flutterwave', 'kora'] as $index => $provider) {
            $payment = Payment::create(['user_id' => $user->id, 'reference' => 'reference-'.$provider, 'provider' => $provider, 'amount' => 50000]);
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['*' => Http::response(['data' => ['status' => $provider === 'flutterwave' ? 'successful' : 'success', 'reference' => $payment->reference, 'tx_ref' => $payment->reference, 'amount' => $provider === 'paystack' ? 50000 : 500, 'currency' => 'NGN', 'id' => 200 + $index]])]);
            $this->assertTrue(app(PaymentGateway::class)->verify($payment));
            $this->assertTrue(app(PaymentGateway::class)->verify($payment->fresh()));
        }
        $this->assertEquals(150000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_entries', 3);
    }

    public function test_wrong_currency_reference_or_amount_never_credits_wallet(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create(['user_id' => $user->id, 'reference' => 'good-reference', 'provider' => 'paystack', 'amount' => 50000]);
        $good = ['status' => 'success', 'reference' => 'good-reference', 'currency' => 'NGN', 'amount' => 50000, 'id' => 1];
        foreach ([['amount' => 1], ['currency' => 'USD'], ['reference' => 'someone-else'], ['status' => 'failed']] as $bad) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['*' => Http::response(['data' => array_replace($good, $bad)])]);
            $this->assertFalse(app(PaymentGateway::class)->verify($payment));
        }
        $this->assertEquals(0, $user->fresh()->wallet_balance);
    }

    public function test_signed_webhook_verifies_server_side_and_replay_is_idempotent(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create(['user_id' => $user->id, 'reference' => 'webhook-ref', 'provider' => 'paystack', 'amount' => 50000]);
        Http::fake(['*' => Http::response(['data' => ['status' => 'success', 'reference' => 'webhook-ref', 'currency' => 'NGN', 'amount' => 50000, 'id' => 44]])]);
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $payment->reference]]);
        $this->call('POST', '/payments/webhook/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'bad'], $body)->assertUnauthorized();
        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/payments/webhook/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'test-secret')], $body)->assertOk();
        }
        $this->assertEquals(50000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_wallet_checkout_and_refund_are_atomic_and_idempotent(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        app(Wallet::class)->change($user->id, 3000000, 'seed-credit', 'Test deposit');
        $this->actingAs($user)->withSession(['cart' => [1 => 1]])->post('/checkout', ['name' => 'Ada', 'phone' => '0801', 'address' => 'Home', 'city' => 'Lagos', 'state' => 'Lagos', 'delivery_rate_id' => \App\Models\DeliveryRate::where('state', 'Lagos')->value('id'), 'terms' => 1, 'payment_method' => 'wallet'])->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals(200000, $user->fresh()->wallet_balance);
        $this->put('/admin/orders/'.$order->id, ['status' => 'cancelled'])->assertRedirect();
        $this->assertEquals(3000000, $user->fresh()->wallet_balance);
        $this->put('/admin/orders/'.$order->id, ['status' => 'cancelled'])->assertSessionHasErrors();
        $this->assertEquals(3000000, $user->fresh()->wallet_balance);
    }

    public function test_insufficient_wallet_balance_rolls_back_order_and_stock(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['cart' => [1 => 1]])->post('/checkout', ['name' => 'Ada', 'phone' => '0801', 'address' => 'Home', 'city' => 'Lagos', 'state' => 'Lagos', 'delivery_rate_id' => \App\Models\DeliveryRate::where('state', 'Lagos')->value('id'), 'terms' => 1, 'payment_method' => 'wallet'])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(100, Product::find(1)->stock);
    }

    public function test_cms_can_change_reorder_hide_and_delete_sections(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user)->get('/admin/homepage')->assertOk();
        $hero = HomepageSection::where('type', 'hero')->firstOrFail();
        $this->put('/admin/sections/'.$hero->id, ['type' => 'hero', 'active' => 1, 'sort_order' => 99, 'content' => ['heading' => 'A new story', 'link' => '#collection']])->assertRedirect();
        $this->get('/')->assertSee('A new story');
        $this->put('/admin/sections/'.$hero->id, ['type' => 'hero', 'sort_order' => 99, 'content' => ['heading' => 'Hidden heading']])->assertRedirect();
        $this->get('/')->assertDontSee('Hidden heading');
        $this->delete('/admin/sections/'.$hero->id)->assertRedirect();
        $this->assertDatabaseMissing('homepage_sections', ['id' => $hero->id]);
        $this->get('/admin/sales')->assertOk();
        $this->get('/admin/customers')->assertOk();
    }

    public function test_pending_online_order_cannot_be_shipped_and_late_payment_becomes_wallet_credit(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user)->withSession(['cart' => [1 => 1]])->post('/checkout', ['name' => 'Ada', 'phone' => '0801', 'address' => 'Home', 'city' => 'Lagos', 'state' => 'Lagos', 'delivery_rate_id' => \App\Models\DeliveryRate::where('state', 'Lagos')->value('id'), 'terms' => 1, 'payment_method' => 'paystack'])->assertRedirect();
        $order = Order::firstOrFail();
        $payment = Payment::firstOrFail();
        $this->put('/admin/orders/'.$order->id, ['status' => 'confirmed'])->assertSessionHasErrors();
        $this->put('/admin/orders/'.$order->id, ['status' => 'cancelled'])->assertRedirect();
        Http::fake(['*' => Http::response(['data' => ['status' => 'success', 'reference' => $payment->reference, 'amount' => $payment->amount, 'currency' => 'NGN', 'id' => 77]])]);
        app(PaymentGateway::class)->verify($payment);
        $this->assertEquals(2800000, $user->fresh()->wallet_balance);
        $this->assertEquals('cancelled', $order->fresh()->status);
    }

    public function test_modern_provider_signatures_are_checked(): void
    {
        $body = json_encode(['data' => ['reference' => 'unknown', 'amount' => 500]]);
        config(['payments.paystack.webhook_secret' => 'whsec_test', 'payments.flutterwave.webhook_secret' => 'flw-hash']);
        $timestamp = time();
        $paystack = 't='.$timestamp.',v1=whk_test:'.hash_hmac('sha512', $timestamp.'.'.$body, 'whsec_test');
        $this->call('POST', '/payments/webhook/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYSTACK_SIGNATURE' => $paystack], $body)->assertOk();
        $this->call('POST', '/payments/webhook/flutterwave', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_FLUTTERWAVE_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, 'flw-hash', true))], $body)->assertOk();
        $this->call('POST', '/payments/webhook/kora', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_KORAPAY_SIGNATURE' => hash_hmac('sha256', json_encode(['reference' => 'unknown', 'amount' => 500]), 'test-secret')], $body)->assertOk();
        $this->postJson('/payments/webhook/flutterwave', ['data' => []])->assertUnauthorized();
        $this->postJson('/payments/webhook/kora', ['data' => []])->assertUnauthorized();
    }

    public function test_setup_admin_must_change_credentials_and_can_do_so(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true, 'must_change_password' => true])->save();
        $this->actingAs($user)->get('/admin')->assertRedirect('/admin/credentials');
        $this->get('/admin/credentials')->assertOk();
        $this->put('/admin/credentials', ['email' => 'owner@example.com', 'current_password' => 'password', 'password' => 'MyNewSecret12345', 'password_confirmation' => 'MyNewSecret12345'])->assertRedirect();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertEquals('owner@example.com', $user->fresh()->email);
        $this->get('/admin')->assertOk();
    }

    public function test_expired_unpaid_reservation_is_restocked_once(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['cart' => [1 => 1]])->post('/checkout', ['name' => 'Ada', 'phone' => '0801', 'address' => 'Home', 'city' => 'Lagos', 'state' => 'Lagos', 'delivery_rate_id' => \App\Models\DeliveryRate::where('state', 'Lagos')->value('id'), 'terms' => 1, 'payment_method' => 'kora']);
        Order::firstOrFail()->update(['created_at' => now()->subHour()]);
        $this->artisan('store:expire-orders')->assertSuccessful();
        $this->artisan('store:expire-orders')->assertSuccessful();
        $this->assertEquals('cancelled', Order::first()->status);
        $this->assertEquals(100, Product::find(1)->stock);
    }

    public function test_payment_and_content_access_are_scoped_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $payment = Payment::create(['user_id' => $owner->id, 'reference' => 'private-payment', 'provider' => 'paystack', 'amount' => 50000]);
        $this->actingAs(User::factory()->create())->get('/payments/'.$payment->reference.'/callback')->assertForbidden();
        $this->get('/payments/'.$payment->reference.'/start')->assertForbidden();
        $this->get('/admin/homepage')->assertForbidden();
        $this->post('/admin/sections', [])->assertForbidden();
    }
}
