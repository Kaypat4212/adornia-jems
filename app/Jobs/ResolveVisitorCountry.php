<?php

namespace App\Jobs;

use App\Models\Visitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ResolveVisitorCountry implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 15;

    public function __construct(public int $visitorId, public string $ipHash) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        if (! config('visitors.country_lookup_enabled', true)) {
            return;
        }
        $visitor = Visitor::whereKey($this->visitorId)->where('ip_hash', $this->ipHash)->first();
        if (! $visitor || ! filter_var($visitor->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return;
        }
        $cacheKey = 'visitor-country:'.$this->ipHash;
        $lock = Cache::lock($cacheKey.':lock', 20);
        if (! $lock->get()) {
            if ($this->job) {
                $this->release(10);
            }
            return;
        }
        try {
            $country = Cache::get($cacheKey);
            if ($country === null) {
                try {
                    $response = Http::connectTimeout(3)->timeout(8)->acceptJson()
                        ->get('https://ipwho.is/'.$visitor->ip_address, ['fields' => 'success,country,country_code']);
                    if (! $response->successful()) {
                        throw new RuntimeException('Country lookup is temporarily unavailable.');
                    }
                    $name = $response->json('country');
                    $code = $response->json('country_code');
                    $country = ['country' => null, 'country_code' => null];
                    if ($response->json('success') === true && is_string($name) && $name !== '' && mb_strlen($name) <= 100
                        && is_string($code) && preg_match('/^[A-Z]{2}$/', $code)) {
                        $country = ['country' => $name, 'country_code' => $code];
                    }
                    Cache::put($cacheKey, $country, $country['country'] ? now()->addDays(7) : now()->addHour());
                } catch (Throwable) {
                    throw new RuntimeException('Visitor country lookup failed. Check server connectivity or the geolocation service limit.');
                }
            }
            Visitor::whereKey($this->visitorId)->where('ip_hash', $this->ipHash)->update($country);
        } finally {
            $lock->release();
        }
    }
}
