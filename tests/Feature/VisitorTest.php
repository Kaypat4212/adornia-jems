<?php

namespace Tests\Feature;

use App\Jobs\DeliverVisitorTelegram;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorAlert;
use App\Models\VisitorSetting;
use App\Services\VisitorTracking;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
    }

    public function test_new_homepage_visitors_must_pass_an_expiring_one_use_captcha(): void
    {
        config(['visitors.captcha_enabled' => true]);
        User::factory()->create(['is_admin' => true]);
        $token = (string) Str::uuid();
        $this->withCookie('adornia_visitor', $token)->get('/')->assertRedirect('/visitor-check');
        $this->assertDatabaseCount('visitor_alerts', 0);
        $challenge = ['hash' => hash_hmac('sha256', 'ABCD23', config('app.key')), 'expires' => time() + 300, 'image' => ''];
        $this->withSession(['visitor_captcha' => $challenge])->post('/visitor-check', ['answer' => 'bad'])
            ->assertSessionHasErrors('answer');
        $this->assertDatabaseCount('visitors', 0);
        $this->withSession(['visitor_captcha' => $challenge])->post('/visitor-check', ['answer' => 'abcd23'])->assertRedirect('/');
        $this->assertDatabaseCount('visitors', 1);
        $this->assertDatabaseCount('visitor_alerts', 1);
        $this->get('/')->assertOk();
        $this->post('/visitor-check', ['answer' => 'ABCD23'])->assertSessionHasErrors('answer');
        $this->withSession(['visitor_captcha' => array_replace($challenge, ['expires' => time() - 1])])
            ->post('/visitor-check', ['answer' => 'ABCD23'])->assertSessionHasErrors('answer');
    }

    public function test_captcha_is_a_private_png_and_only_a_hash_is_stored(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('PHP GD is required to render the CAPTCHA.');
        }
        $this->get('/visitor-check')->assertOk()->assertSee('Verification code');
        $challenge = session('visitor_captcha');
        $this->assertSame(64, strlen($challenge['hash']));
        $this->assertArrayNotHasKey('answer', $challenge);
        $this->get('/visitor-check/image')->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_repeat_pages_and_cookie_changes_do_not_repeat_ip_alerts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        config(['visitors.captcha_enabled' => false]);
        $this->withCookie('adornia_visitor', (string) Str::uuid())->get('/')->assertOk();
        $this->get('/')->assertOk();
        $this->post('/visitor/heartbeat', ['path' => '/'])->assertOk();
        $this->withCookie('adornia_visitor', (string) Str::uuid())->get('/')->assertOk();
        $this->assertDatabaseCount('visitor_alerts', 1);
        $this->assertSame(1, EmailDelivery::where('recipient', $admin->email)->count());
        $this->actingAs(User::factory()->create())->get('/')->assertOk();
        $this->assertDatabaseCount('visitor_alerts', 2);
        $this->get('/')->assertOk();
        $this->assertDatabaseCount('visitor_alerts', 2);
    }

    public function test_new_ips_get_one_new_alert_and_heartbeat_cannot_bypass_captcha(): void
    {
        $tracking = app(VisitorTracking::class);
        $token = (string) Str::uuid();
        foreach (['192.0.2.1', '192.0.2.2', '192.0.2.2'] as $ip) {
            $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip]);
            $tracking->record($request, $token, verified: true);
        }
        $this->assertDatabaseCount('visitor_alerts', 2);
        config(['visitors.captcha_enabled' => true]);
        $this->post('/visitor/heartbeat', ['path' => '/'])->assertForbidden();
        $this->assertDatabaseCount('visitor_alerts', 2);
    }

    public function test_telegram_alerts_are_optional_deduplicated_and_keep_tokens_secret(): void
    {
        $token = '123456:telegram_private_test_value';
        VisitorSetting::create(['id' => 1, 'content' => ['captcha_enabled' => false, 'telegram_enabled' => true,
            'telegram_token' => $token, 'telegram_chat' => '-123456']]);
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '192.0.2.3']);
        app(VisitorTracking::class)->record($request, (string) Str::uuid());
        Queue::assertPushed(DeliverVisitorTelegram::class, 1);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $alert = VisitorAlert::firstOrFail();
        $job = new DeliverVisitorTelegram($alert->id);
        $job->handle();
        $job->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], '192.0.2.x') && ! str_contains($request['text'], '192.0.2.3'));
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/visitors')
            ->assertOk()->assertDontSee($token);
    }

    public function test_admin_dashboard_and_settings_are_protected_and_live_counts_expire(): void
    {
        $this->get('/admin/visitors/live')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/visitors')->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->put('/admin/visitors/settings', [
            'current_password' => UserFactory::password(), 'loader' => 'dots', 'email_enabled' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame('dots', VisitorSetting::values()['loader']);
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '192.0.2.1']);
        app(VisitorTracking::class)->record($request, (string) Str::uuid());
        $this->get('/admin/visitors/live')->assertJsonPath('active', 1);
        $this->travel(91)->seconds();
        $this->get('/admin/visitors/live')->assertJsonPath('active', 0);
        $count = Visitor::count();
        $this->get('/')->assertOk();
        $this->assertSame($count, Visitor::count());
    }
}
