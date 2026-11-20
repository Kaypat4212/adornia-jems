@extends('layouts.store')
@section('title', 'Error logs')
@section('content')
<h1 class="page-title">Error logs</h1>
<p>Recent Laravel and PHP errors. Showing up to 300 lines from the last 64 KB. Credential details are redacted.</p>
@if($logs)
<form method="get" action="{{ route('admin.logs') }}">
    <label>Log file<select name="source">
        @foreach($logs as $id => $log)<option value="{{ $id }}" @selected($selected === $id)>{{ $log['name'] }}</option>@endforeach
    </select></label>
    <button class="cta">VIEW / REFRESH LOG</button>
</form>
<pre class="panel" style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:65vh;overflow:auto;margin-top:24px">{{ $content ?: 'This log is empty.' }}</pre>
<details class="panel">
    <summary>Clear error logs</summary>
    <p>This permanently clears log contents. Log files remain in place so Laravel and PHP can keep recording new errors. Email delivery history is preserved.</p>
    <form method="post" action="{{ route('admin.logs.clear') }}">
        @csrf @method('DELETE')
        <input type="hidden" name="source" value="{{ $selected }}">
        <label>Logs to clear<select name="scope"><option value="selected">Currently selected log</option><option value="all">All listed Laravel and PHP logs</option></select></label>
        <label>Current admin password<input type="password" name="current_password" autocomplete="current-password" required></label>
        <label class="check"><input type="checkbox" name="confirm_clear" value="1" required>I understand that the selected log contents will be permanently cleared.</label>
        <button class="cta">CLEAR LOG CONTENTS</button>
    </form>
</details>
@else
<p>No readable Laravel or PHP log files are available yet. Laravel writes application errors to storage/logs. PHP log availability depends on the hosting configuration.</p>
@endif
@endsection
