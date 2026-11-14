<?php
namespace Tests\Feature;

use App\Models\{DeliveryRate, EmailDelivery, EmailSetting, Order, Payment, StoreSetting, User};
use App\Services\{Delivery, EmailConfiguration, StoreEmail};
use App\Jobs\DeliverStoreEmail;
use App\Mail\StoreMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Mail, Storage};
use Tests\TestCase;

class DeliveryAndEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        return $user;
    }

    private function lagos(int $fee = 2500, bool $cod = true): DeliveryRate
    {
        $rate = DeliveryRate::where('state', 'Lagos')->firstOrFail();
        $rate->update(['active' => true, 'fee' => $fee, 'pay_on_delivery' => $cod]);
        return $rate;
    }

    private function address(DeliveryRate $rate): array
    {
        return ['name' => 'Ada', 'phone' => '08012345678', 'address' => '1 Sample Street', 'city' => 'Lagos', 'state' => 'Lagos', 'delivery_rate_id' => $rate->id, 'terms' => 1, 'payment_method' => 'cash_on_delivery'];
    }

    public function test_checkout_has_no_raw_blade_and_lists_nigerian_states(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['cart' => [1 => 1]])->get('/checkout')->assertOk()->assertDontSee('@php(')->assertSee('Enugu')->assertSee('Lagos')->assertSee('FCT')->assertSee('proof is required');
        $this->getJson('/checkout/delivery-options?state=Lagos')->assertOk()->assertJsonCount(0, 'rates');
        $this->getJson('/checkout/delivery-options?state=Invalid')->assertUnprocessable();
    }

    public function test_server_charges_admin_delivery_fee_and_preserves_order_snapshot(): void
    {
        $rate = $this->lagos();
        $this->actingAs(User::factory()->create())->withSession(['cart' => [1 => 1]])->post('/checkout', $this->address($rate) + ['delivery_fee' => 1, 'total' => 1])->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertEquals(30500, $order->total);
        $this->assertEquals(2500, $order->delivery_fee);
        $this->assertEquals('Within Lagos', $order->delivery_zone);
        $rate->update(['fee' => 9000]);
        $this->assertEquals(2500, $order->fresh()->delivery_fee);
        $this->assertDatabaseCount('email_deliveries', 1);
    }

    public function test_pay_on_delivery_cannot_be_forged_when_disabled_for_state(): void
    {
        $rate = $this->lagos(2500, false);
        $this->actingAs(User::factory()->create())->withSession(['cart' => [1 => 1]])->post('/checkout', $this->address($rate))->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
    }

    public function test_state_specific_disabled_zone_is_not_bypassed_by_interstate_fallback(): void
    {
        DeliveryRate::where('state', '*')->update(['active' => true, 'fee' => 7000]);
        $this->assertCount(0, app(Delivery::class)->options('Enugu'));
        $this->assertCount(1, app(Delivery::class)->options('Kano'));
    }

    public function test_interstate_and_state_rates_are_validated_against_destination(): void
    {
        $interstate = DeliveryRate::where('state', '*')->firstOrFail();
        $interstate->update(['active' => true, 'fee' => 7000]);
        $this->assertCount(1, app(Delivery::class)->options('Kano'));
        $lagos = $this->lagos();
        $this->actingAs(User::factory()->create())->withSession(['cart' => [1 => 1]])->post('/checkout', array_replace($this->address($lagos), ['state' => 'Enugu']))->assertSessionHasErrors('delivery_rate_id');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_admin_can_manage_zones_but_cannot_enable_statewide_cod_fallback(): void
    {
        $this->actingAs($this->admin())->get('/admin/delivery')->assertOk();
        $data = ['name' => 'Lagos Island', 'state' => 'Lagos', 'fee' => 4000, 'active' => 1, 'pay_on_delivery' => 1];
        $this->post('/admin/delivery', $data)->assertRedirect();
        $rate = DeliveryRate::where('name', 'Lagos Island')->firstOrFail();
        $this->assertTrue($rate->pay_on_delivery);
        $this->put('/admin/delivery/'.$rate->id, array_replace($data, ['fee' => 4500]))->assertRedirect();
        $this->post('/admin/delivery', array_replace($data, ['state' => '*']))->assertSessionHasErrors('pay_on_delivery');
        $this->delete('/admin/delivery/'.$rate->id)->assertRedirect();
        $this->assertFalse($rate->fresh()->active);
        $this->actingAs(User::factory()->create())->post('/admin/delivery', $data)->assertForbidden();
    }

    public function test_manual_proof_is_required_and_notifies_customer_and_admin(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $user = User::factory()->create();
        $payment = Payment::create(['user_id' => $user->id, 'provider' => 'manual', 'amount' => 100000, 'reference' => 'manual-proof']);
        $this->actingAs($user)->post('/payments/'.$payment->reference.'/manual', ['transfer_reference' => 'tx123'])->assertSessionHasErrors('receipt');
        $this->assertEquals('pending', $payment->fresh()->status);
        $this->post('/payments/'.$payment->reference.'/manual', ['transfer_reference' => 'tx123', 'receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 receipt')])->assertRedirect();
        $this->assertDatabaseCount('email_deliveries', 2);
        $this->assertDatabaseHas('email_deliveries', ['recipient' => $admin->email, 'subject' => 'Transfer proof received']);
        Storage::disk('local')->assertExists($payment->fresh()->receipt);
        $this->assertFalse(Storage::disk('public')->exists($payment->fresh()->receipt));
        $this->get('/admin/payments/'.$payment->id.'/receipt')->assertForbidden();
    }

    public function test_successful_payment_notifications_are_deduplicated(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $payment = Payment::create(['user_id' => $user->id, 'provider' => 'paystack', 'amount' => 100000, 'reference' => 'notification-test']);
        $payment->update(['status' => 'succeeded']);
        app(StoreEmail::class)->paymentUpdated($payment);
        $this->assertDatabaseCount('email_deliveries', 2);
    }

    public function test_email_job_sends_once_and_admin_password_is_not_exposed(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);
        $delivery = EmailDelivery::create(['event_key' => 'job-test', 'recipient' => 'customer@example.com', 'subject' => 'Order update', 'message' => 'Your order is confirmed.', 'action_path' => '/account']);
        $job = new DeliverStoreEmail($delivery->id);
        $job->handle(app(EmailConfiguration::class));
        $job->handle(app(EmailConfiguration::class));
        Mail::assertSent(StoreMessage::class, 1);
        $this->assertEquals('sent', $delivery->fresh()->status);
        $this->assertEquals(1, $delivery->fresh()->attempts);
        $this->actingAs($this->admin())->put('/admin/email-settings', ['current_password' => 'password', 'driver' => 'smtp', 'from_address' => 'store@example.com', 'from_name' => 'Adornia', 'admin_email' => 'owner@example.com', 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'store@example.com', 'password' => 'private-smtp-password'])->assertRedirect();
        $this->assertStringNotContainsString('private-smtp-password', DB::table('email_settings')->value('content'));
        $this->get('/admin/email-settings')->assertOk()->assertDontSee('private-smtp-password');
    }
}
