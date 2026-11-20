<?php

namespace Tests\Feature;

use App\Jobs\DeliverStoreEmail;
use App\Mail\StoreMessage;
use App\Models\EmailDelivery;
use App\Models\EmailSetting;
use App\Models\User;
use App\Services\EmailConfiguration;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminEmailToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_failed_and_logged_notifications_can_be_sent_manually(): void
    {
        Mail::fake();
        config(['mail.default' => 'array']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['pending', 'failed', 'logged'] as $status) {
            $delivery = $this->delivery($status);
            $this->post('/admin/email-deliveries/'.$delivery->id.'/retry')->assertSessionHasNoErrors();
            $this->assertSame('sent', $delivery->fresh()->status);
            $this->post('/admin/email-deliveries/'.$delivery->id.'/retry')->assertSessionHasErrors('email');
            (new DeliverStoreEmail($delivery->id))->handle(app(EmailConfiguration::class));
            $this->assertSame(1, $delivery->fresh()->attempts);
        }
        Mail::assertSent(StoreMessage::class, 3);
    }

    public function test_manual_send_does_not_overlap_an_existing_attempt(): void
    {
        Mail::fake();
        $delivery = $this->delivery();
        $lock = Cache::lock('store-email-send:'.$delivery->id, 120);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs(User::factory()->create(['is_admin' => true]))
                ->post('/admin/email-deliveries/'.$delivery->id.'/retry')->assertRedirect();
            Mail::assertNothingSent();
            $this->assertSame(0, $delivery->fresh()->attempts);
        } finally {
            $lock->release();
        }
    }

    public function test_smtp_test_uses_saved_configuration_and_records_delivery(): void
    {
        Mail::fake();
        EmailSetting::create(['id' => 1, 'content' => [
            'driver' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls',
            'from_address' => 'shop@example.test', 'from_name' => 'Adornia', 'password' => 'smtp-test-secret',
        ]]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/email-settings/test', ['recipient' => 'owner@example.test'])->assertSessionHasNoErrors();
        Mail::assertSent(StoreMessage::class, fn ($mail) => $mail->hasTo('owner@example.test'));
        $this->assertDatabaseHas('email_deliveries', ['recipient' => 'owner@example.test', 'status' => 'sent', 'attempts' => 1]);
    }

    public function test_smtp_test_rejects_log_mode_and_invalid_recipients(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/admin/email-settings/test', ['recipient' => 'bad'])->assertSessionHasErrors('recipient');
        $this->post('/admin/email-settings/test', ['recipient' => 'owner@example.test'])->assertSessionHasErrors('email');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('email_deliveries', 0);
    }

    public function test_mail_errors_are_recorded_and_can_be_retried(): void
    {
        config(['mail.default' => 'array']);
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('getDefaultDriver')->andReturn('array');
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('550 message rejected'));
        $delivery = $this->delivery();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/email-deliveries/'.$delivery->id.'/retry')->assertSessionHasErrors('email');
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertStringContainsString('550', $delivery->fresh()->last_error);
        Mail::fake();
        $this->post('/admin/email-deliveries/'.$delivery->id.'/retry')->assertSessionHasNoErrors();
        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_logs_are_admin_only_redacted_escaped_and_path_restricted(): void
    {
        $this->get('/admin/logs')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/logs')->assertForbidden();
        $this->post('/admin/email-settings/test', ['recipient' => 'owner@example.test'])->assertForbidden();
        $this->post('/admin/email-deliveries/'.$this->delivery()->id.'/retry')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $path = storage_path('logs/laravel-admin-test-'.Str::uuid().'.log');
        config(['mail.mailers.smtp.password' => 'smtp-visible-value']);
        file_put_contents($path, "ERROR example failure\npassword=hidden-value\nAuthentication failed for smtp-visible-value\nsmtp://user:other-private-value@host\n<script>alert(1)</script>\n");
        try {
            $this->get('/admin/logs?source='.hash('sha256', $path))->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('example failure')->assertDontSee('hidden-value')
                ->assertDontSee('smtp-visible-value')->assertDontSee('other-private-value')
                ->assertSee('&lt;script&gt;', false);
            $this->get('/admin/logs?source=../../.env')->assertNotFound();
        } finally {
            unlink($path);
        }
    }

    public function test_clearing_logs_requires_an_admin_password_confirmation_and_an_allowed_source(): void
    {
        $path = storage_path('logs/laravel-clear-test-'.Str::uuid().'.log');
        $other = storage_path('logs/laravel-keep-test-'.Str::uuid().'.log');
        $outside = storage_path('not-a-log-'.Str::uuid().'.txt');
        file_put_contents($path, 'Old error contents');
        file_put_contents($other, 'Keep this other log');
        file_put_contents($outside, 'Keep this file');
        $data = ['source' => hash('sha256', $path), 'scope' => 'selected',
            'confirm_clear' => 1, 'current_password' => UserFactory::password()];
        try {
            $this->delete('/admin/logs', $data)->assertRedirect('/login');
            $this->actingAs(User::factory()->create())->delete('/admin/logs', $data)->assertForbidden();
            $this->actingAs(User::factory()->create(['is_admin' => true]));
            $this->delete('/admin/logs', array_replace($data, ['current_password' => 'incorrect']))
                ->assertSessionHasErrors('current_password');
            $this->delete('/admin/logs', array_replace($data, ['confirm_clear' => 0]))
                ->assertSessionHasErrors('confirm_clear');
            $this->delete('/admin/logs', array_replace($data, ['source' => hash('sha256', $outside)]))->assertNotFound();
            $this->assertSame('Old error contents', file_get_contents($path));
            $this->delete('/admin/logs', $data)->assertRedirect(route('admin.logs', ['source' => $data['source']]));
            $this->assertFileExists($path);
            $this->assertSame('', file_get_contents($path));
            $this->assertSame('Keep this other log', file_get_contents($other));
            $this->assertSame('Keep this file', file_get_contents($outside));
            file_put_contents($path, 'New error', FILE_APPEND);
            $this->assertSame('New error', file_get_contents($path));
        } finally {
            unlink($path);
            unlink($other);
            unlink($outside);
        }
    }

    private function delivery(string $status = 'pending'): EmailDelivery
    {
        return EmailDelivery::create([
            'event_key' => (string) Str::uuid(), 'recipient' => 'customer@example.test',
            'subject' => 'Order update', 'message' => 'Your order is ready.', 'status' => $status,
        ]);
    }
}
