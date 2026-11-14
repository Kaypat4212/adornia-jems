<?php
namespace App\Http\Controllers;
use App\Models\{Payment, WalletEntry};
use App\Services\PaymentGateway;
use Illuminate\Http\{Request, RedirectResponse, JsonResponse};
use Illuminate\Support\Str;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function wallet(Request $request): View
    {
        return view('wallet', ['entries' => WalletEntry::where('user_id', $request->user()->id)->latest()->paginate(20), 'payments' => Payment::where('user_id', $request->user()->id)->latest()->limit(20)->get()]);
    }

    public function deposit(Request $request, PaymentGateway $gateway): RedirectResponse
    {
        $data = $request->validate(['amount' => 'required|integer|min:100|max:1000000', 'provider' => 'required|in:paystack,flutterwave,kora,manual']);
        if (! $gateway->enabled($data['provider'])) {
            return back()->withErrors(['payment' => 'This provider needs its API keys before deposits can be accepted.']);
        }
        $payment = Payment::create(['user_id' => $request->user()->id, 'provider' => $data['provider'], 'reference' => 'AJ-'.Str::uuid(), 'amount' => $data['amount'] * 100, 'details' => $data['provider'] === 'manual' ? app(\App\Services\GatewayConfiguration::class)->bank() : null]);

        return redirect()->route($payment->provider === 'manual' ? 'manual.show' : 'payments.start', $payment->reference);
    }

    public function start(Request $request, Payment $payment, PaymentGateway $gateway): RedirectResponse
    {
        abort_unless($payment->user_id === $request->user()->id, 403);
        if ($payment->status === 'succeeded') {
            return redirect($payment->order_id ? '/orders/'.$payment->order_id : '/wallet');
        }
        if ($payment->order_id && \App\Models\Order::findOrFail($payment->order_id)->status === 'cancelled') {
            return redirect('/wallet')->withErrors(['payment' => 'This order was cancelled. Create a new order.']);
        }
        if ($payment->provider === 'manual') {
            return redirect()->route('manual.show', $payment->reference);
        }
        try {
            return redirect()->away($gateway->initialize($payment));
        } catch (\Throwable $exception) {
            report($exception);
            return redirect($payment->order_id ? '/orders/'.$payment->order_id : '/wallet')->withErrors(['payment' => 'The payment provider is unavailable. Your payment remains pending; you can retry or check its status.']);
        }
    }

    public function callback(Request $request, Payment $payment, PaymentGateway $gateway): RedirectResponse
    {
        abort_unless($payment->user_id === $request->user()->id, 403);
        if ($payment->provider === 'manual') {
            return redirect()->route('manual.show', $payment->reference);
        }
        try {
            $verified = $gateway->verify($payment, $request->query('transaction_id'));
        } catch (\Throwable $exception) {
            report($exception);
            $verified = false;
        }

        return redirect($payment->order_id ? '/orders/'.$payment->order_id : '/wallet')->with('status', $verified ? 'Payment verified successfully.' : 'Payment has not been confirmed yet. You can check again from your wallet or order.');
    }

    public function webhook(Request $request, string $provider, PaymentGateway $gateway): JsonResponse
    {
        $reference = $provider === 'flutterwave' ? $request->input('data.tx_ref') : $request->input('data.reference');
        $payment = Payment::where('provider', $provider)->where('reference', $reference)->first();
        abort_unless($gateway->validSignature($request, $provider, $payment?->mode ?? $request->route('mode')), 401);
        if ($payment) {
            $gateway->verify($payment, $provider === 'flutterwave' ? (string) $request->input('data.id') : null);
        }

        return response()->json(['received' => true]);
    }
}
