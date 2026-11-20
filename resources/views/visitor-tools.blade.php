<meta name="page-loader" content="{{ App\Models\VisitorSetting::values()['loader'] }}">
<link rel="stylesheet" href="{{ asset('visitor-tools.css') }}?v={{ filemtime(public_path('visitor-tools.css')) }}">
<script src="{{ asset('visitor-tools.js') }}?v={{ filemtime(public_path('visitor-tools.js')) }}" defer></script>
