<?php
namespace App\Http\Controllers;
use App\Models\RefundAccount;
use App\Services\GatewayConfiguration;
use Illuminate\Http\{Request, JsonResponse, RedirectResponse};
use Illuminate\Support\Facades\{Cache, Http};
use Illuminate\Validation\ValidationException;

class RefundAccountController extends Controller
{
    public function banks(GatewayConfiguration $configuration): JsonResponse
    {
        $secret = $configuration->credential('paystack');
        if (! $secret) {
            return response()->json(['message' => 'Bank verification is not configured. Please try again later.'], 503);
        }
        try {
            $banks = Cache::remember('paystack-banks:'.hash('sha256', $secret), 3600, function () use ($secret): array {
                $banks = collect();
                $cursor = null;
                for ($page = 0; $page < 10; $page++) {
                    $parameters = ['country' => 'nigeria', 'currency' => 'NGN', 'perPage' => 100, 'use_cursor' => true];
                    if ($cursor) {
                        $parameters['next'] = $cursor;
                    }
                    $response = Http::withToken($secret)->timeout(15)->get('https://api.paystack.co/bank', $parameters);
                    $response->throw();
                    if ($response->json('status') !== true) {
                        throw new \RuntimeException('Bank list is unavailable.');
                    }
                    $banks = $banks->merge($response->json('data', []));
                    $next = $response->json('meta.next');
                    if (! $next || $next === $cursor) {
                        break;
                    }
                    $cursor = $next;
                }

                return $banks->unique('code')->sortBy('name')->map(fn ($bank) => ['code' => (string) $bank['code'], 'name' => $bank['name']])->values()->all();
            });
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'The bank list is temporarily unavailable. Please retry.'], 503);
        }

        return response()->json(['banks' => $banks, 'mode' => $configuration->mode('paystack')]);
    }

    public function store(Request $request, GatewayConfiguration $configuration): RedirectResponse
    {
        $data = $request->validate(['bank_code' => 'required|string|max:20', 'account_number' => 'required|digits:10', 'current_password' => 'required|current_password']);
        $secret = $configuration->credential('paystack');
        if (! $secret) {
            throw ValidationException::withMessages(['bank' => 'Paystack bank verification is not configured yet.']);
        }
        try {
            $bankResponse = $this->banks($configuration);
            $banks = $bankResponse->getData(true)['banks'] ?? [];
            $bank = collect($banks)->firstWhere('code', $data['bank_code']);
            if (! $bank) {
                throw new \RuntimeException('Bank code unavailable.');
            }
            $response = Http::withToken($secret)->timeout(15)->get('https://api.paystack.co/bank/resolve', ['bank_code' => $data['bank_code'], 'account_number' => $data['account_number']]);
            $response->throw();
            if ($response->json('status') !== true || $response->json('data.account_number') !== $data['account_number'] || ! filled($response->json('data.account_name'))) {
                throw new \RuntimeException('Account not resolved.');
            }
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['bank' => 'We could not verify this bank account with Paystack. Check the bank and account number, then try again.']);
        }
        $fingerprint = hash_hmac('sha256', $data['bank_code'].':'.$data['account_number'], config('app.key'));
        RefundAccount::updateOrCreate(['user_id' => $request->user()->id, 'account_fingerprint' => $fingerprint], ['bank_code' => $bank['code'], 'bank_name' => $bank['name'], 'account_number' => $data['account_number'], 'account_name' => $response->json('data.account_name'), 'verification_mode' => $configuration->mode('paystack'), 'verified_at' => now()]);

        return back()->with('status', 'Refund account saved with the account name returned by Paystack.');
    }

    public function destroy(Request $request, RefundAccount $account): RedirectResponse
    {
        abort_unless($account->user_id === $request->user()->id, 403);
        $request->validate(['current_password' => 'required|current_password']);
        $account->delete();

        return back()->with('status', 'Refund account removed.');
    }
}
