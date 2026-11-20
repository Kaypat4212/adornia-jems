<?php

namespace App\Services;

use App\Jobs\DeliverVisitorTelegram;
use App\Jobs\ResolveVisitorCountry;
use App\Models\Visitor;
use App\Models\VisitorAlert;
use App\Models\VisitorSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VisitorTracking
{
    public function token(Request $request): string
    {
        $token = $request->cookie('adornia_visitor');
        if (! is_string($token) || ! Str::isUuid($token)) {
            $token = (string) Str::uuid();
            Cookie::queue(cookie('adornia_visitor', $token, 43200, '/', null, $request->secure(), true, false, 'lax'));
        }

        return $token;
    }

    public function ipHash(Request $request): string
    {
        return hash_hmac('sha256', $request->ip() ?? 'unknown', config('app.key'));
    }

    public function known(Request $request): ?Visitor
    {
        $token = $request->cookie('adornia_visitor');

        return is_string($token) ? Visitor::where('token_hash', hash('sha256', $token))->first() : null;
    }

    public function verified(Request $request): bool
    {
        $visitor = $this->known($request);

        return $visitor?->verified_at !== null && hash_equals($visitor->ip_hash, $this->ipHash($request));
    }

    public function record(Request $request, string $token, bool $verified = false, string $path = '/'): Visitor
    {
        return DB::transaction(function () use ($request, $token, $verified, $path): Visitor {
            $ipHash = $this->ipHash($request);
            $visitor = Visitor::firstOrCreate(['token_hash' => hash('sha256', $token)], [
                'ip_hash' => $ipHash, 'ip_address' => $request->ip() ?? 'unknown', 'last_seen_at' => now(),
            ]);
            if ($visitor->ip_hash !== $ipHash) {
                $visitor->verified_at = null;
                $visitor->country = null;
                $visitor->country_code = null;
            }
            $visitor->fill(['ip_hash' => $ipHash, 'ip_address' => $request->ip() ?? 'unknown',
                'user_id' => $request->user()?->id, 'last_seen_at' => now(), 'path' => $path,
                'device_type' => app(VisitorDevice::class)->type($request->userAgent())]);
            if ($verified) {
                $visitor->verified_at = now();
            }
            $lookupCountry = config('visitors.country_lookup_enabled', true) && $visitor->country_lookup_hash !== $ipHash;
            if ($lookupCountry) {
                $visitor->country_lookup_hash = $ipHash;
            }
            $visitor->save();
            if ($lookupCountry && filter_var($request->ip(), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                ResolveVisitorCountry::dispatch($visitor->id, $ipHash)->onConnection('database')->onQueue('emails')->afterCommit();
            }

            $newIp = DB::table('visitor_identities')->insertOrIgnore(['identity' => 'ip:'.$ipHash, 'created_at' => now()]);
            $newUser = $request->user() ? DB::table('visitor_identities')->insertOrIgnore([
                'identity' => 'user:'.$request->user()->id, 'created_at' => now(),
            ]) : 0;
            if ($newIp || $newUser) {
                $settings = VisitorSetting::values();
                $reason = $newUser ? 'New signed-in account' : 'New IP address';
                $alert = VisitorAlert::create(['visitor_id' => $visitor->id, 'reason' => $reason,
                    'telegram_status' => $settings['telegram_enabled'] ? 'pending' : 'disabled']);
                if ($settings['email_enabled']) {
                    app(StoreEmail::class)->admins('visitor:'.$alert->id, 'New Adornia Jems visitor',
                        $reason.'. Visitor #'.$visitor->id.' · '.$visitor->maskedIp().'. View the visitor dashboard for activity.', '/admin/visitors');
                }
                if ($settings['telegram_enabled']) {
                    DeliverVisitorTelegram::dispatch($alert->id)->onConnection('database')->onQueue('emails')->afterCommit();
                }
            }

            return $visitor;
        });
    }
}
