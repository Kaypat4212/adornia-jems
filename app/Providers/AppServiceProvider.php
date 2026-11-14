<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Models\Payment::updated(function (\App\Models\Payment $payment): void {
            if ($payment->wasChanged('status')) {
                app(\App\Services\StoreEmail::class)->paymentUpdated($payment);
            }
        });
        \App\Models\Order::updated(function (\App\Models\Order $order): void {
            if ($order->wasChanged('status')) {
                app(\App\Services\StoreEmail::class)->orderUpdated($order);
            }
        });
    }
}
