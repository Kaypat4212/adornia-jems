@extends('layouts.store')
@section('title', 'Customer details')
@section('content')
<span class="eyebrow">Customer care</span><h1 class="page-title">{{ $customer->name }}.</h1>
<div class="columns"><div class="panel">
@if($customer->avatar)<img class="profile-avatar" src="{{ asset('storage/'.$customer->avatar) }}" alt="Customer profile photo">@endif
<h2>Contact & balance</h2><p>{{ $customer->email }}<br>{{ $customer->phone }}<br>{{ $customer->address }}</p><p>Spendable wallet: ₦{{ number_format($customer->wallet_balance / 100, 2) }}<br>Test balance: ₦{{ number_format($customer->wallet_test_balance / 100, 2) }}</p>
</div><div class="panel"><h2>Refund bank accounts</h2><p>These are customer-saved accounts resolved by Paystack. This page does not send a bank transfer. Cancelled paid orders are credited to the platform wallet.</p>
@forelse($accounts as $account)<div class="refund-account"><div><strong>{{ $account->account_name }}</strong><p>{{ $account->bank_name }} · ••••••{{ substr($account->account_number, -4) }}</p><span class="status-chip">{{ strtoupper($account->verification_mode) }} · {{ $account->verified_at->format('j M Y') }}</span>
@if($account->verification_mode === 'live')<details><summary>View verified refund details</summary><p>Account number: {{ $account->account_number }}<br>Bank code: {{ $account->bank_code }}</p></details>@else<p>Test verification. Ask the customer to re-verify in live mode before arranging a real bank refund.</p>@endif
</div></div>@empty<p>No verified accounts saved.</p>@endforelse</div></div>
<h2>Order history</h2>@foreach($orders as $order)<a class="order-link" href="/orders/{{ $order->id }}">{{ $order->reference }} · ₦{{ number_format($order->total) }} · {{ $order->status }}</a>@endforeach{{ $orders->links() }}
@endsection
