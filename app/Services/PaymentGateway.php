<?php
namespace App\Services;
use App\Models\{Payment, User, Order};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Http, DB};
use Illuminate\Validation\ValidationException;

class PaymentGateway
{
    public function enabled(string $provider): bool
    {
        return app(GatewayConfiguration::class)->enabled($provider);
    }

    public function initialize(Payment $payment): string
    {
        if (! filled(app(GatewayConfiguration::class)->credential($payment->provider, 'secret', $payment->mode))) {
            throw ValidationException::withMessages(['payment' => 'This payment provider is not configured yet.']);
        }
        if ($payment->checkout_url) {
            return $payment->checkout_url;
        }
        $user = User::findOrFail($payment->user_id);
        $callback = route('payments.callback', ['payment' => $payment->reference]);
        $base = ['currency' => 'NGN', 'reference' => $payment->reference, 'amount' => $payment->amount / 100];
        $http = Http::withToken(app(GatewayConfiguration::class)->credential($payment->provider, 'secret', $payment->mode))->acceptJson()->timeout(20)->connectTimeout(10);
        $response = match ($payment->provider) {
            'paystack' => $http->post('https://api.paystack.co/transaction/initialize', array_replace($base, ['amount' => $payment->amount, 'email' => $user->email, 'callback_url' => $callback])),
            'flutterwave' => $http->post('https://api.flutterwave.com/v3/payments', ['tx_ref' => $payment->reference, 'amount' => $payment->amount / 100, 'currency' => 'NGN', 'redirect_url' => $callback, 'customer' => ['email' => $user->email, 'name' => $user->name]]),
            'kora' => $http->post('https://api.korapay.com/merchant/api/v1/charges/initialize', $base + ['redirect_url' => $callback, 'notification_url' => route('payments.webhook', 'kora'), 'customer' => ['email' => $user->email, 'name' => $user->name], 'merchant_bears_cost' => true]),
        };
        $response->throw();
        $url = $response->json(match ($payment->provider) {'paystack' => 'data.authorization_url', 'flutterwave' => 'data.link', 'kora' => 'data.checkout_url'});
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('The provider did not return a checkout link.');
        }
        $payment->update(['checkout_url' => $url]);

        return $url;
    }

    public function verify(Payment $payment, ?string $transactionId = null): bool
    {
        if ($payment->status === 'succeeded') {
            return true;
        }
        abort_unless(filled(app(GatewayConfiguration::class)->credential($payment->provider, 'secret', $payment->mode)), 503);
        $http = Http::withToken(app(GatewayConfiguration::class)->credential($payment->provider, 'secret', $payment->mode))->acceptJson()->timeout(20)->connectTimeout(10);
        $response = match ($payment->provider) {
            'paystack' => $http->get('https://api.paystack.co/transaction/verify/'.rawurlencode($payment->reference)),
            'flutterwave' => $transactionId ? $http->get('https://api.flutterwave.com/v3/transactions/'.rawurlencode($transactionId).'/verify') : $http->get('https://api.flutterwave.com/v3/transactions/verify_by_reference', ['tx_ref' => $payment->reference]),
            'kora' => $http->get('https://api.korapay.com/merchant/api/v1/charges/'.rawurlencode($payment->reference)),
        };
        $response->throw();
        $data = $response->json('data', []);
        $reference = $payment->provider === 'flutterwave' ? ($data['tx_ref'] ?? '') : ($data['reference'] ?? '');
        $amount = $payment->provider === 'paystack' ? (int) ($data['amount'] ?? 0) : (int) round(((float) ($data['amount'] ?? 0)) * 100);
        if (! in_array($data['status'] ?? '', ['success', 'successful']) || $reference !== $payment->reference || ($data['currency'] ?? '') !== 'NGN' || $amount !== (int) $payment->amount) {
            return false;
        }
        DB::transaction(function () use ($payment, $data): void {
            $locked = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($locked->status === 'succeeded') {
                return;
            }
            $order = $locked->order_id ? Order::lockForUpdate()->findOrFail($locked->order_id) : null;
            if (! $order || $order->status === 'cancelled') {
                app(Wallet::class)->change($locked->user_id, $locked->amount, 'payment:'.$locked->reference, $order ? 'Late payment credited to wallet' : 'Wallet deposit via '.$locked->provider, $locked->mode);
            } else {
                $order->update(['payment_status' => 'paid']);
            }
            $locked->update(['status' => 'succeeded', 'provider_id' => (string) ($data['id'] ?? $data['reference'])]);
        });

        return true;
    }

    public function validSignature(Request $request, string $provider, ?string $mode = null): bool
    {
        if (! filled(app(GatewayConfiguration::class)->credential($provider, 'secret', $mode))) {
            return false;
        }
        $body = $request->getContent();
        $secret = app(GatewayConfiguration::class)->credential($provider, 'secret', $mode);
        if ($provider === 'paystack') {
            if ($request->hasHeader('Paystack-Signature')) {
                $signingSecret = app(GatewayConfiguration::class)->credential('paystack', 'webhook_secret', $mode);
                preg_match('/(?:^|,)t=(\d+)/', $request->header('Paystack-Signature'), $time);
                preg_match_all('/v1=[^:,]+:([a-f0-9]{128})/', $request->header('Paystack-Signature'), $signatures);
                if (! $signingSecret || ! isset($time[1]) || abs(time() - (int) $time[1]) > 300) {
                    return false;
                }
                $expected = hash_hmac('sha512', $time[1].'.'.$body, $signingSecret);
                return collect($signatures[1])->contains(fn ($signature) => hash_equals($expected, $signature));
            }
            return hash_equals(hash_hmac('sha512', $body, $secret), $request->header('x-paystack-signature', ''));
        }
        if ($provider === 'flutterwave') {
            $hash = app(GatewayConfiguration::class)->credential('flutterwave', 'webhook_secret', $mode);
            if (! $hash) {
                return false;
            }
            if ($request->hasHeader('flutterwave-signature')) {
                return hash_equals(base64_encode(hash_hmac('sha256', $body, $hash, true)), $request->header('flutterwave-signature'));
            }
            return hash_equals($hash, $request->header('verif-hash', ''));
        }
        $data = json_encode($request->input('data'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash_equals(hash_hmac('sha256', $data, $secret), $request->header('x-korapay-signature', ''));
    }
}
