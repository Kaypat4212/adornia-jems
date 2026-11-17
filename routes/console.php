<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('store:admin {email}', function () {
    $user = \App\Models\User::where('email', $this->argument('email'))->first();
    if (! $user) {
        $this->error('Register this account through the store first.');
        return 1;
    }
    $user->forceFill(['is_admin' => true])->save();
    $this->info('Administrator access granted.');
    return 0;
})->purpose('Grant store administration to an existing registered account');

Artisan::command('store:setup-admin {email?}', function () {
    if (\App\Models\User::where('is_admin', true)->exists()) {
        $this->error('An administrator already exists. Use your existing account or password reset.');
        return 1;
    }
    $email = trim((string) ($this->argument('email') ?? $this->ask('Administrator email')));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $this->error('Enter a valid administrator email address.');

        return 1;
    }

    $user = \App\Models\User::firstOrNew(['email' => $email]);
    if ($user->exists) {
        $this->error('Setup email is already registered. Promote an existing account with store:admin instead.');
        return 1;
    }
    $password = \Illuminate\Support\Str::password(20);
    $user->forceFill(['name' => 'Adornia Administrator', 'password' => \Illuminate\Support\Facades\Hash::make($password), 'is_admin' => true, 'must_change_password' => true])->save();
    $this->info('Email: '.$user->email);
    $this->info('Temporary password: '.$password);
    $this->info('Change these credentials at /admin/credentials after signing in.');
    return 0;
})->purpose('Create the initial administrator with a unique temporary password');

Artisan::command('store:expire-orders', function () {
    $count = 0;
    \App\Models\Order::where('status', 'pending')->where('payment_status', 'unpaid')->whereIn('payment_method', ['paystack', 'flutterwave', 'kora'])->where('created_at', '<', now()->subMinutes(30))->each(function ($order) use (&$count): void {
        \Illuminate\Support\Facades\DB::transaction(function () use ($order, &$count): void {
            $locked = \App\Models\Order::lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending' || $locked->payment_status !== 'unpaid') {
                return;
            }
            foreach ($locked->items as $item) {
                if ($locked->payment_mode !== 'test') {
                    \App\Models\Product::withTrashed()->whereKey($item->product_id)->increment('stock', $item->quantity);
                }
            }
            $locked->update(['status' => 'cancelled']);
            $count++;
        });
    });
    $this->info($count.' unpaid reservations expired. Any later verified payment is credited to the customer wallet.');
})->purpose('Release online order stock reservations after 30 minutes');

\Illuminate\Support\Facades\Schedule::command('store:expire-orders')->everyTenMinutes()->withoutOverlapping();
