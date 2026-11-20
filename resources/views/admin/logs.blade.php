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
@else
<p>No readable Laravel or PHP log files are available yet. Laravel writes application errors to storage/logs. PHP log availability depends on the hosting configuration.</p>
@endif
@endsection
