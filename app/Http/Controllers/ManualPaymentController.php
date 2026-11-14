<?php
namespace App\Http\Controllers;
use App\Models\{Payment, Order};
use App\Services\Wallet;
use Illuminate\Http\{Request, RedirectResponse};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManualPaymentController extends Controller
{
    public function show(Request $request, Payment $payment): View
    {
        abort_unless($payment->provider === 'manual' && $payment->user_id === $request->user()->id, 403);

        return view('manual-payment', compact('payment'));
    }

    public function submit(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->provider === 'manual' && $payment->user_id === $request->user()->id, 403);
        $data = $request->validate(['receipt' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120', 'transfer_reference' => 'required|string|max:120']);
        DB::transaction(function () use ($request, $payment, $data): void {
            $locked = Payment::lockForUpdate()->findOrFail($payment->id);
            if (! in_array($locked->status, ['pending', 'rejected'])) {
                throw ValidationException::withMessages(['payment' => 'This payment is already under review or completed.']);
            }
            $locked->update(['receipt' => $request->file('receipt')->store('receipts', 'local'), 'details' => array_replace($locked->details ?? [], ['transfer_reference' => $data['transfer_reference']]), 'status' => 'under_review']);
        });

        return back()->with('status', 'Proof of transfer submitted. An administrator will confirm the deposit in the company bank account.');
    }

    public function receipt(Payment $payment): StreamedResponse
    {
        abort_unless($payment->provider === 'manual' && $payment->receipt, 404);

        return Storage::disk('local')->download($payment->receipt);
    }

    public function review(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->provider === 'manual', 404);
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'bank_reference' => 'required_if:decision,approve|nullable|string|max:120', 'review_note' => 'required|string|max:1000', 'current_password' => 'required|current_password']);
        DB::transaction(function () use ($request, $payment, $data): void {
            $locked = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== 'under_review') {
                throw ValidationException::withMessages(['payment' => 'Only payments awaiting review can be processed.']);
            }
            if ($data['decision'] === 'approve') {
                $bankReference = strtoupper(trim($data['bank_reference']));
                if (Payment::where('provider', 'manual')->where('mode', 'live')->where('provider_id', $bankReference)->exists()) {
                    throw ValidationException::withMessages(['payment' => 'This bank transaction has already been used to confirm another payment.']);
                }
                $locked->update(['provider_id' => $bankReference]);
                $order = $locked->order_id ? Order::lockForUpdate()->findOrFail($locked->order_id) : null;
                if ($order && $order->status !== 'cancelled') {
                    $order->update(['payment_status' => 'paid']);
                } else {
                    app(Wallet::class)->change($locked->user_id, $locked->amount, 'payment:'.$locked->reference, 'Confirmed bank transfer', 'live');
                }
            }
            $locked->update(['status' => $data['decision'] === 'approve' ? 'succeeded' : 'rejected', 'review_note' => $data['review_note'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        });

        return back()->with('status', 'Manual transfer reviewed.');
    }
}
