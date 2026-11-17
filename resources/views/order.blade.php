@extends('layouts.store')
@section('title', 'Order '.$order->reference)
@section('content')<span class="eyebrow">Your Adornia order</span><h1 class="page-title">{{ $order->reference }}</h1><div class="columns"><div class="panel"><h2>{{ ucfirst($order->status) }}</h2>
@if($order->status !== 'cancelled')
<ol class="order-progress" aria-label="Order progress">
    @foreach(['pending' => 'Placed', 'confirmed' => 'Confirmed', 'shipped' => 'Shipped', 'delivered' => 'Delivered'] as $step => $label)
        <li @if($order->status === $step) aria-current="step" @endif>{{ $label }}</li>
    @endforeach
</ol>
@endif
@if($order->tracking_number)
    <div class="notice shipment-details"><strong>Shipment tracking</strong><p>{{ $order->carrier }} · {{ $order->tracking_number }}</p><button class="text-button" type="button" data-copy="{{ $order->tracking_number }}">Copy tracking number</button></div>
@endif
<p>Placed {{ $order->created_at->format('j M Y, H:i') }} · Payment {{ $order->payment_status }} · {{ strtoupper($order->payment_mode) }}</p>@foreach($order->items as $item)<div class="cart-row"><span>{{ $item->name }} × {{ $item->quantity }}</span><strong>₦{{ number_format($item->price * $item->quantity) }}</strong></div>@endforeach<div class="cart-row"><span>Delivery · {{ $order->delivery_zone }}</span><strong>₦{{ number_format($order->delivery_fee) }}</strong></div><div class="total"><strong>Total</strong><strong>₦{{ number_format($order->total) }}</strong></div><p>{{ str_replace('_', ' ', $order->payment_method) }} · Delivery included in total</p>@php
$payment = App\Models\Payment::where('order_id', $order->id)->first();
@endphp
 @if($payment && $payment->status !== 'succeeded' && $order->status !== 'cancelled')<p><a class="cta" href="{{ route('payments.start', $payment->reference) }}">{{ $payment->provider === 'manual' ? 'UPLOAD / VIEW TRANSFER PROOF' : 'CONTINUE PAYMENT ↗' }}</a></p><p><a href="{{ route('payments.callback', $payment->reference) }}">Check payment status</a></p>@endif<div class="order-actions">
<button class="cta" onclick="window.print()">PRINT ORDER</button>
@if($order->user_id === auth()->id())
    <form method="post" action="{{ route('orders.reorder', $order) }}">@csrf<button class="text-button">Buy these pieces again ↗</button></form>
@endif
</div></div><div class="panel"><h2>Deliver to</h2><p>{{ $order->name }}<br>{{ $order->phone }}<br>{{ $order->address }}<br>{{ $order->city }}, {{ $order->state }}<br>Nigeria</p>@if(auth()->user()->is_admin)
@if(in_array($order->status, ['confirmed', 'shipped']))
<form method="post" action="{{ route('admin.orders.tracking', $order) }}">
    @csrf @method('PUT')
    <h3>Shipment tracking</h3>
    <label>Carrier<input name="carrier" maxlength="80" required value="{{ old('carrier', $order->carrier) }}"></label>
    <label>Tracking number<input name="tracking_number" maxlength="120" required value="{{ old('tracking_number', $order->tracking_number) }}"></label>
    <button class="cta">SAVE TRACKING</button>
</form>
@endif
<form action="{{ route('admin.orders.update', $order) }}" method="post">@csrf @method('PUT')<label>Update fulfillment<select name="status">@foreach(['confirmed', 'shipped', 'delivered', 'cancelled'] as $status)<option value="{{ $status }}">{{ ucfirst($status) }}</option>@endforeach</select></label>@if($order->payment_mode === 'test')<p class="notice">This is a sandbox order and cannot be fulfilled.</p>@endif<p>Allowed sequence: pending → confirmed → shipped → delivered. Pending or confirmed orders may be cancelled. Paid cancellations are refunded to the customer wallet. Mark delivered only once payment has been collected.</p><button class="cta">UPDATE ORDER</button></form>@endif</div></div>@endsection