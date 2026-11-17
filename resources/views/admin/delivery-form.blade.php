<form method="post" action="{{ url('/admin/delivery' . ($rate ? '/'.$rate->id : '')) }}">
@csrf
@if($rate) @method('PUT') @endif
<div class="columns"><label>Zone / service name<input name="name" value="{{ $rate?->name }}" placeholder="e.g. Lagos Mainland" required maxlength="100"></label>
<label>State<select name="state"><option value="*" @selected($rate?->state === '*')>Interstate fallback (states without their own zones)</option>@foreach($states as $state)<option value="{{ $state }}" @selected($rate?->state === $state)>{{ $state }}</option>@endforeach</select></label>
<label>Fee (whole naira)<input name="fee" value="{{ $rate?->fee }}" type="number" min="0" max="1000000" step="1" placeholder="Not set"></label>
<label>Delivery estimate (optional)<input name="estimate" value="{{ $rate?->estimate }}" placeholder="e.g. 2–4 business days" maxlength="150"></label></div>
<label class="check"><input type="checkbox" name="active" value="1" @checked($rate?->active)>Accept deliveries in this zone</label>
<label class="check"><input type="checkbox" name="pay_on_delivery" value="1" @checked($rate?->pay_on_delivery)>Allow pay on delivery in this state / area</label>
<button class="cta">SAVE DELIVERY ZONE</button></form>
