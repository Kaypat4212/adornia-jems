<?php
namespace App\Services;
use App\Models\{User, WalletEntry};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Wallet
{
    public function change(int $userId, int $amount, string $reference, string $description, string $mode = 'live'): void
    {
        DB::transaction(function () use ($userId, $amount, $reference, $description, $mode): void {
            $balanceField = $mode === 'test' ? 'wallet_test_balance' : 'wallet_balance';
            $user = User::lockForUpdate()->findOrFail($userId);
            if (WalletEntry::where('reference', $reference)->exists()) {
                return;
            }
            if ($user->{$balanceField} + $amount < 0) {
                throw ValidationException::withMessages(['payment' => 'Your wallet balance is insufficient. Add funds or choose another payment method.']);
            }
            $user->forceFill([$balanceField => $user->{$balanceField} + $amount])->save();
            WalletEntry::create(['user_id' => $userId, 'amount' => $amount, 'balance_after' => $user->{$balanceField}, 'reference' => $reference, 'description' => $description, 'mode' => $mode]);
        });
    }
}
