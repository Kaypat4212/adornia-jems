<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use App\Models\EmailSetting;
use App\Models\User;
use App\Services\PhpMailTransport;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class PhpMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_php_mail_sends_mime_content_and_keeps_blind_recipients_private(): void
    {
        $calls = [];
        $transport = new PhpMailTransport(function (...$arguments) use (&$calls): bool {
            $calls[] = $arguments;
            return true;
        });
        $transport->send((new Email)->from('shop@example.test')->to('customer@example.test')
            ->bcc('owner@example.test')->subject('Jewelry update · Adornia')->html('<h1>Your order</h1>'));
        $this->assertCount(2, $calls);
        $this->assertSame('customer@example.test', $calls[0][0]);
        $this->assertSame('owner@example.test', $calls[1][0]);
        $this->assertStringContainsString('Your order', $calls[0][2]);
        $this->assertStringContainsString('Content-Type: text/html', $calls[0][3]);
        $this->assertStringContainsString('Message-ID:', $calls[0][3]);
        $this->assertStringNotContainsString('Bcc:', $calls[0][3]);
        $this->assertStringNotContainsString('owner@example.test', $calls[0][3]);
    }

    public function test_php_mail_rejection_is_reported_as_a_failure(): void
    {
        $transport = new PhpMailTransport(fn (): bool => false);
        $this->expectException(TransportException::class);
        $transport->send((new Email)->from('shop@example.test')->to('customer@example.test')->subject('Test')->text('Test'));
    }

    public function test_admin_can_save_php_mail_and_send_a_tracked_test_without_smtp_credentials(): void
    {
        $calls = [];
        app('mail.manager')->extend('php', function (array $config) use (&$calls): PhpMailTransport {
            return new PhpMailTransport(function (...$arguments) use (&$calls): bool {
                $calls[] = $arguments;
                return true;
            });
        });
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->put('/admin/email-settings', [
            'current_password' => UserFactory::password(), 'driver' => 'php',
            'from_address' => 'shop@example.test', 'from_name' => 'Adornia',
            'admin_email' => 'owner@example.test', 'encryption' => 'tls',
        ])->assertSessionHasNoErrors();
        $this->assertSame('php', EmailSetting::findOrFail(1)->content['driver']);
        $this->post('/admin/email-settings/test', ['recipient' => 'owner@example.test'])->assertSessionHasNoErrors();
        $delivery = EmailDelivery::firstOrFail();
        $this->assertSame('php', $delivery->transport);
        $this->assertSame('sent', $delivery->status);
        $this->assertNotEmpty($delivery->message_id);
        $this->assertCount(1, $calls);
        $this->get('/admin/email-settings')->assertOk()->assertSee('Accepted by mail server')->assertSee($delivery->message_id);
    }
}
