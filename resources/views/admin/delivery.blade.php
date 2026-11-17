@extends('layouts.store')
@section('title', 'Delivery zones')
@section('content')
<span class="eyebrow">Made for Nigeria</span><h1 class="page-title">Delivery, by location.</h1>
<p>Set prices for Lagos, Enugu, interstate deliveries, or a specific state and area. You can add Lagos Mainland and Island as separate zones. A state-specific zone takes priority over the interstate fallback, even when disabled.</p>
<div class="notice">Pay on delivery is available only in the specific state zones you enable. Unconfigured fees block checkout; enter 0 deliberately if delivery is free.</div>
@foreach($rates as $rate)
<details class="panel"><summary>{{ $rate->name }} · {{ $rate->state === '*' ? 'Other states / interstate' : $rate->state }} · {{ $rate->active ? 'Active' : 'Disabled' }} · {{ $rate->fee === null ? 'Fee not set' : '₦'.number_format($rate->fee) }}</summary>
@include('admin.delivery-form')
<form method="post" action="{{ url('/admin/delivery/' . ($rate->id)) }}">@csrf @method('DELETE')<button class="text-button">Disable this zone</button></form>
</details>
@endforeach
<div class="panel"><h2>Add a delivery area</h2>@include('admin.delivery-form', ['rate' => null])</div>
@endsection
