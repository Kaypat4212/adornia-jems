@extends('layouts.store')
@section('title', 'Email notifications')
@section('content')<span class="eyebrow">Keep customers in the loop</span><h1 class="page-title">Email notifications.</h1>
<p>Customers receive welcome, order, payment, transfer-proof and fulfillment updates. Admins receive new-order and payment-review notifications. Password reset emails use the same mail settings.</p>
<form class="panel" method="post" action="{{ url('/admin/email-settings') }}" autocomplete="off">@csrf @method('PUT')<div class="columns">
<label>Delivery mode<select name="driver"><option value="log" @selected(($settings['driver'] ?? config('mail.default')) === 'log')>Local log — no email is sent</option><option value="smtp" @selected(($settings['driver'] ?? config('mail.default')) === 'smtp')>SMTP — send email</option></select></label>
<label>Admin notification email<input name="admin_email" type="email" value="{{ $settings['admin_email'] ?? auth()->user()->email }}" required></label>
<label>From email<input name="from_address" type="email" value="{{ $settings['from_address'] ?? config('mail.from.address') }}" required></label>
<label>From name<input name="from_name" value="{{ $settings['from_name'] ?? 'Adornia Jems' }}" required></label>
<label>SMTP host<input name="host" value="{{ $settings['host'] ?? '' }}" placeholder="smtp.your-provider.com"></label>
<label>SMTP port<input name="port" type="number" min="1" max="65535" value="{{ $settings['port'] ?? 587 }}"></label>
<label>Encryption<select name="encryption"><option value="tls" @selected(($settings['encryption'] ?? 'tls') === 'tls')>STARTTLS (usually 587)</option><option value="ssl" @selected(($settings['encryption'] ?? '') === 'ssl')>Implicit TLS (usually 465)</option></select></label>
<label>SMTP username<input name="username" value="{{ $settings['username'] ?? '' }}" autocomplete="off"></label>
<label>SMTP password<input name="password" type="password" autocomplete="new-password" placeholder="{{ filled($settings['password'] ?? null) ? 'Saved · leave blank to keep' : 'Not saved' }}"></label>
<label>Current admin password<input name="current_password" type="password" required autocomplete="current-password"></label></div><button class="cta">SAVE EMAIL SETTINGS</button></form>
<div class="notice">Order and payment emails are queued so mail outages cannot block checkout. Run <code>php artisan queue:work database --queue=emails --tries=3</code> on your server. Local log mode records messages without sending them.</div>
<h2>Notification delivery log</h2><div class="table-wrap"><table><tr><th>Recipient</th><th>Subject</th><th>Status</th><th>Attempts</th><th>Actions</th></tr>@forelse($deliveries as $delivery)<tr><td>{{ $delivery->recipient }}</td><td>{{ $delivery->subject }}</td><td>{{ $delivery->status }}</td><td>{{ $delivery->attempts }}</td><td>@if($delivery->status === 'failed')<p>{{ $delivery->last_error }}</p><form method="post" action="{{ url('/admin/email-deliveries/' . ($delivery->id) . '/retry') }}">@csrf<button class="text-button">Retry</button></form>@endif</td></tr>@empty<tr><td colspan="5">No notifications queued yet.</td></tr>@endforelse</table></div>{{ $deliveries->links() }}
@endsection
