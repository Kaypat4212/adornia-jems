<?php
namespace App\Http\Controllers;
use App\Models\{GatewaySetting, StoreSetting};
use Illuminate\Http\{Request, RedirectResponse};
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class GatewaySettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.gateways', ['gateways' => GatewaySetting::all()->keyBy('provider'), 'bank' => app(\App\Services\GatewayConfiguration::class)->bank()]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['paystack', 'flutterwave', 'kora']), 404);
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|current_password', 'mode' => 'required|in:test,live', 'enabled' => 'nullable|boolean',
            'keys' => 'nullable|array', 'keys.*' => 'array', 'keys.*.*' => 'nullable|string|max:2048',
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $setting = GatewaySetting::firstOrNew(['provider' => $provider]);
        $credentials = $setting->credentials ?? [];
        foreach (['test', 'live'] as $mode) {
            foreach (['secret', 'public', 'webhook_secret'] as $key) {
                $value = trim((string) $request->input("keys.$mode.$key", ''));
                if ($value !== '') {
                    if ($provider === 'paystack' && in_array($key, ['secret', 'public']) && ! str_starts_with($value, ($key === 'secret' ? 'sk_' : 'pk_').$mode.'_')) {
                        return back()->withErrors(['keys' => "The Paystack $mode $key key has the wrong prefix."]);
                    }
                    if ($provider === 'flutterwave' && in_array($key, ['secret', 'public'])) {
                        $prefix = $key === 'secret' ? 'FLWSECK' : 'FLWPUBK';
                        if (! str_starts_with($value, $prefix) || str_contains($value, '_TEST') !== ($mode === 'test')) {
                            return back()->withErrors(['keys' => "The Flutterwave key does not match $mode mode."]);
                        }
                    }
                    if ($provider === 'kora' && in_array($key, ['secret', 'public']) && str_contains(strtolower($value), $mode === 'test' ? '_live_' : '_test_')) {
                        return back()->withErrors(['keys' => "The Kora key does not match $mode mode."]);
                    }
                    $credentials[$mode][$key] = $value;
                }
            }
        }
        if ($request->boolean('enabled') && empty($credentials[$request->input('mode')]['secret'])) {
            return back()->withErrors(['keys' => 'Add a secret key for the selected mode before enabling this gateway.']);
        }
        if ($provider === 'flutterwave' && $request->boolean('enabled') && empty($credentials[$request->input('mode')]['webhook_secret'])) {
            return back()->withErrors(['keys' => 'Add the Flutterwave webhook hash for the selected mode before enabling it.']);
        }
        $setting->fill(['mode' => $request->input('mode'), 'enabled' => $request->boolean('enabled'), 'credentials' => $credentials])->save();

        return back()->with('status', ucfirst($provider).' settings saved. Existing payments retain their original test/live mode.');
    }

    public function bank(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => 'required|current_password', 'enabled' => 'nullable|boolean', 'bank_name' => 'required|string|max:100', 'account_number' => 'required|digits:10', 'account_name' => 'required|string|max:150', 'instructions' => 'nullable|string|max:1000']);
        unset($data['current_password']);
        $data['enabled'] = $request->boolean('enabled');
        $setting = StoreSetting::findOrFail(1);
        $setting->update(['content' => array_replace($setting->content, ['manual_bank' => $data])]);

        return back()->with('status', 'Company bank details saved.');
    }
}
