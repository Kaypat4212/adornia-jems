@extends('layouts.store')
@section('title', 'Checkout')
@section('content')
<span class="eyebrow">One step closer</span>
<h1 class="page-title">Make it yours.</h1>
<div class="columns" data-checkout data-subtotal="{{ $subtotal }}">
    <form class="panel" method="post" action="/checkout">
        @csrf
        <h2>Delivery details</h2>
        @foreach(['name' => 'Full name', 'phone' => 'Phone number', 'address' => 'Street address', 'city' => 'City / town'] as $key => $label)
        <label>{{ $label }}<input name="{{ $key }}" value="{{ old($key, $user->{$key} ?? '') }}" required maxlength="{{ $key === 'address' ? 1000 : ($key === 'phone' ? 30 : 100) }}"></label>
        @endforeach
        <label>State / FCT
            <select name="state" data-delivery-state required>
                <option value="">Select your state</option>
                @foreach($states as $state)
                <option value="{{ $state }}" @selected(old('state') === $state)>{{ $state }}</option>
                @endforeach
            </select>
        </label>
        <label>Delivery area / service
            <select name="delivery_rate_id" data-delivery-rate data-selected="{{ old('delivery_rate_id') }}" required disabled><option value="">Choose a state first</option></select>
        </label>
        <p data-delivery-message role="status">Select your state to see delivery prices and payment options.</p>
        <p>Delivery country: Nigeria. Choose the delivery area that matches your address.</p>
        <label>Payment method
            <select name="payment_method" data-payment-method required>
                <option value="wallet" @selected(old('payment_method') === 'wallet')>Wallet · ₦{{ number_format($user->wallet_balance / 100, 2) }} available</option>
                @foreach(['paystack','flutterwave','kora','manual'] as $provider)
                <option value="{{ $provider }}" @selected(old('payment_method') === $provider) @disabled(!app(App\Services\PaymentGateway::class)->enabled($provider))>{{ $provider === 'manual' ? 'Manual bank transfer · upload proof after ordering' : ucfirst($provider).' · '.app(App\Services\GatewayConfiguration::class)->mode($provider) }}{{ app(App\Services\PaymentGateway::class)->enabled($provider) ? '' : ' — not configured' }}</option>
                @endforeach
                <option value="cash_on_delivery" data-cod-option @selected(old('payment_method') === 'cash_on_delivery') disabled hidden>Pay on delivery</option>
            </select>
        </label>
        <div data-manual-instructions class="notice" hidden>
            <strong>Manual bank transfer: proof is required.</strong>
            <p>Place your order, transfer the exact total to the company account shown on the next page, then upload a receipt or screenshot. Your payment stays pending until an admin confirms receipt.</p>
        </div>
        <p><a href="/wallet">Add money to your wallet ↗</a></p>
        <label class="check"><input type="checkbox" name="terms" value="1" required @checked(old('terms'))>I confirm my delivery address and agree to the selected delivery fee and payment method.</label>
        <button class="cta" data-place-order disabled>PLACE ORDER ↗</button>
        <noscript><p>JavaScript is required to load delivery prices. Enable it and reload checkout.</p></noscript>
    </form>
    <div class="panel">
        <h2>Order summary</h2>
        @foreach($lines as $line)
        <div class="cart-row"><span>{{ $line->name }} × {{ $line->quantity }}</span><strong>₦{{ number_format($line->price * $line->quantity) }}</strong></div>
        @endforeach
        <div class="cart-row"><span>Items subtotal</span><strong>₦{{ number_format($subtotal) }}</strong></div>
        <div class="cart-row"><span>Delivery</span><strong data-delivery-fee>Select location</strong></div>
        <div class="total"><span>Total · NGN</span><strong data-checkout-total>—</strong></div>
        <p>Delivery fees and stock are checked again when you order. Pay on delivery is offered only in zones enabled by the admin.</p>
        <a href="/#collection">Edit your bag ↗</a>
    </div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('checkout.js') }}" defer></script>
@endpush
