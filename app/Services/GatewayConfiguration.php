<?php
namespace App\Services;
use App\Models\{GatewaySetting, StoreSetting};

class GatewayConfiguration
{
    public function mode(string $provider): string
    {
        return GatewaySetting::where('provider', $provider)->value('mode') ?? config('payments.default_mode', 'test');
    }

    public function credential(string $provider, string $key = 'secret', ?string $mode = null): ?string
    {
        $setting = GatewaySetting::where('provider', $provider)->first();
        $mode ??= $this->mode($provider);
        if ($setting) {
            return $setting->credentials[$mode][$key] ?? null;
        }

        return $mode === config('payments.default_mode', 'test') ? config("payments.$provider.$key") : null;
    }

    public function enabled(string $provider): bool
    {
        if ($provider === 'manual') {
            $bank = $this->bank();
            return ($bank['enabled'] ?? false) && filled($bank['bank_name'] ?? '') && filled($bank['account_name'] ?? '') && filled($bank['account_number'] ?? '');
        }
        if (! in_array($provider, ['paystack', 'flutterwave', 'kora'])) {
            return false;
        }
        $setting = GatewaySetting::where('provider', $provider)->first();

        return ($setting?->enabled ?? true) && filled($this->credential($provider));
    }

    public function bank(): array
    {
        return StoreSetting::find(1)?->content['manual_bank'] ?? [];
    }
}
