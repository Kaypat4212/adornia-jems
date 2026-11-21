<?php

namespace Tests\Feature;

use App\Jobs\DeliverVisitorTelegram;
use App\Jobs\ResolveVisitorCountry;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorAlert;
use App\Models\VisitorSetting;
use App\Services\VisitorTracking;
use App\Services\VisitorDevice;
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
        $page = $this->get('/visitor-check')->assertOk()->assertSee('Verification code')
            ->assertSee('src="data:image/png;base64,', false)
            ->assertDontSee('/visitor-check/image')
            ->assertHeader('Cache-Control', 'no-store, private');
        preg_match('/src="data:image\/png;base64,([A-Za-z0-9+\/=]+)"/', $page->getContent(), $matches);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", base64_decode($matches[1]));
        $challenge = session('visitor_captcha');
        $this->assertSame(64, strlen($challenge['hash']));
        $this->assertArrayNotHasKey('answer', $challenge);
        $this->assertArrayNotHasKey('image', $challenge);
        $this->get('/visitor-check/image')->assertRedirect('/visitor-check')
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

    public function test_device_types_are_detected_and_updated_when_the_browser_changes(): void
    {
        $detector = app(VisitorDevice::class);
        foreach ([
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile/15E148' => 'Mobile',
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile Safari/537.36' => 'Mobile',
            'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X)' => 'Tablet',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) Mobile/15E148 Safari/604.1' => 'Tablet',
            'Mozilla/5.0 (Linux; Android 13; SM-X700) Safari/537.36' => 'Tablet',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' => 'Desktop',
            'Mozilla/5.0 (X11; Linux x86_64)' => 'Desktop',
            'Googlebot/2.1 Mobile' => 'Bot',
            '' => 'Unknown',
        ] as $agent => $expected) {
            $this->assertSame($expected, $detector->type($agent));
        }
        $tracking = app(VisitorTracking::class);
        $token = (string) Str::uuid();
        $request = Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'iPhone Mobile']);
        $visitor = $tracking->record($request, $token);
        $this->assertSame('Mobile', $visitor->device_type);
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0)');
        $this->assertSame('Desktop', $tracking->record($request, $token)->device_type);
        Queue::assertNotPushed(ResolveVisitorCountry::class);
    }

    public function test_country_is_resolved_in_the_background_cached_and_shown_to_admin(): void
    {
        Http::fake(['ipwho.is/*' => Http::response(['success' => true, 'country' => 'Nigeria', 'country_code' => 'NG'])]);
        $tracking = app(VisitorTracking::class);
        $request = Request::create('/', server: ['REMOTE_ADDR' => '8.8.8.8', 'HTTP_USER_AGENT' => 'iPhone Mobile']);
        $token = (string) Str::uuid();
        $visitor = $tracking->record($request, $token);
        $tracking->record($request, $token);
        Queue::assertPushed(ResolveVisitorCountry::class, 1);
        Http::assertNothingSent();
        (new ResolveVisitorCountry($visitor->id, $visitor->ip_hash))->handle();
        $other = $tracking->record($request, (string) Str::uuid());
        (new ResolveVisitorCountry($other->id, $other->ip_hash))->handle();
        Http::assertSentCount(1);
        $this->assertSame('Nigeria', $visitor->fresh()->country);
        $this->assertSame('NG', $other->fresh()->country_code);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/visitors/live')
            ->assertJsonFragment(['country' => 'Nigeria', 'country_code' => 'NG', 'device' => 'Mobile']);
        $this->get('/admin/visitors')->assertOk()->assertSee('Nigeria')->assertSee('Mobile')
            ->assertSee('Country: Nigeria')->assertSee('Device type: Mobile')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_changed_ips_clear_old_countries_and_stale_jobs_cannot_overwrite_them(): void
    {
        $tracking = app(VisitorTracking::class);
        $token = (string) Str::uuid();
        $request = Request::create('/', server: ['REMOTE_ADDR' => '8.8.8.8']);
        $visitor = $tracking->record($request, $token);
        $oldHash = $visitor->ip_hash;
        $visitor->update(['country' => 'Nigeria', 'country_code' => 'NG']);
        $request = Request::create('/', server: ['REMOTE_ADDR' => '1.1.1.1']);
        $visitor = $tracking->record($request, $token);
        $this->assertNull($visitor->country);
        Http::fake(['ipwho.is/*' => Http::response([], 503)]);
        (new ResolveVisitorCountry($visitor->id, $oldHash))->handle();
        Http::assertNothingSent();
        try {
            (new ResolveVisitorCountry($visitor->id, $visitor->ip_hash))->handle();
            $this->fail('A failed geolocation service should trigger a queue retry.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Visitor country lookup failed', $exception->getMessage());
        }
        $this->assertNull($visitor->fresh()->country);
        $tracking->record($request, $token);
        Queue::assertPushed(ResolveVisitorCountry::class, 2);
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
