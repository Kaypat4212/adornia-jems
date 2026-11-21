@extends('layouts.store')
@section('title', 'Live visitors')
@section('content')
<h1 class="page-title">Live visitors</h1>
<p><strong data-active-visitors>{{ $active }}</strong> active in the last 90 seconds. Admin visits are excluded.</p>
<p>Country is estimated from the visitor’s IP using ipwho.is and updates through the emails queue. Device type is estimated from the browser. VPNs and browser settings can affect these estimates; unavailable details show as Unknown. Existing visitors update on their next visit.</p>
<div data-live-visitors data-live-url="{{ route('admin.visitors.live') }}" class="panel" aria-live="polite">Live activity refreshes every 15 seconds.</div>
<details class="panel"><summary>CAPTCHA, alerts and loaders</summary>
<form method="post" action="{{ route('admin.visitors.settings') }}" autocomplete="off">
    @csrf @method('PUT')
    <label class="check"><input type="checkbox" name="captcha_enabled" value="1" @checked($settings['captcha_enabled'])>Require CAPTCHA for new browsers and IP addresses</label>
    <p>CAPTCHA codes expire after five minutes. PHP GD is {{ function_exists('imagecreatetruecolor') ? 'available' : 'missing' }}.</p>
    <label class="check"><input type="checkbox" name="email_enabled" value="1" @checked($settings['email_enabled'])>Email admins about new IP addresses or accounts</label>
    <label class="check"><input type="checkbox" name="telegram_enabled" value="1" @checked($settings['telegram_enabled'])>Send new visitor alerts to Telegram</label>
    <label>Telegram bot token<input type="password" name="telegram_token" autocomplete="new-password" placeholder="{{ $settings['telegram_token'] ? 'Saved · leave blank to keep' : 'BotFather token' }}"></label>
    <label>Telegram chat ID<input name="telegram_chat" value="{{ $settings['telegram_chat'] }}"></label>
    <p>Start a chat with your bot first, or add it to your group. Alerts include a visitor reference and masked IP address. Each IP or signed-in account triggers a new-visitor alert once.</p>
    <label>Page loader<select name="loader">@foreach(['bar' => 'Progress bar', 'ring' => 'Spinning ring', 'dots' => 'Bouncing dots', 'pulse' => 'Soft pulse', 'diamond' => 'Rotating diamond', 'none' => 'No loader'] as $value => $label)<option value="{{ $value }}" @selected($settings['loader'] === $value)>{{ $label }}</option>@endforeach</select></label>
    <label>Current admin password<input type="password" name="current_password" autocomplete="current-password" required></label>
    <button class="cta">SAVE VISITOR SETTINGS</button>
</form></details>
<h2>Recent visitors</h2>
<p>Older visits may show Unknown until that visitor returns. Country also needs the notification queue worker to finish its lookup.</p>
<div class="table-wrap"><table><thead><tr><th>Visitor</th><th>IP</th><th class="visitor-detail-column">Country</th><th class="visitor-detail-column">Device type</th><th>Account</th><th>Page</th><th>Last seen</th></tr></thead><tbody>
@forelse($visitors as $visitor)<tr><td>#{{ $visitor->id }}<span class="visitor-mobile-details"><span>Country: {{ $visitor->country ?? 'Unknown' }}</span><span>Device type: {{ $visitor->device_type ?: 'Unknown' }}</span></span></td><td>{{ $visitor->maskedIp() }}</td><td class="visitor-detail-column">{{ $visitor->country ?? 'Unknown' }}</td><td class="visitor-detail-column">{{ $visitor->device_type ?: 'Unknown' }}</td><td>{{ $visitor->user_id ? '#'.$visitor->user_id : 'Guest' }}</td><td>{{ $visitor->path }}</td><td>{{ $visitor->last_seen_at->diffForHumans() }}</td></tr>@empty<tr><td colspan="7">No visitors recorded yet.</td></tr>@endforelse
</tbody></table></div>{{ $visitors->links() }}
<h2>New visitor alerts</h2>
<p>Email attempts are available in <a href="{{ url('/admin/email-settings') }}">Email notifications</a>. Email and Telegram delivery use the emails queue.</p>
<div class="table-wrap"><table><tr><th>Visitor</th><th>Reason</th><th>Telegram</th><th>Created</th></tr>@foreach($alerts as $alert)<tr><td>#{{ $alert->visitor_id }}</td><td>{{ $alert->reason }}</td><td>{{ $alert->telegram_status }} {{ $alert->last_error }}</td><td>{{ $alert->created_at->diffForHumans() }}</td></tr>@endforeach</table></div>
@endsection
