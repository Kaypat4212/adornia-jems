@extends('layouts.store')
@section('title', 'Visitor verification')
@section('content')
<div class="auth-card panel">
    <h1 class="page-title">Welcome to Adornia Jems</h1>
    <p>Please enter the six characters in the image to continue. This check is required for a new browser or IP address.</p>
    <form method="post" action="{{ route('visitor.verify') }}">
        @csrf
        <img src="data:image/png;base64,{{ $captchaImage }}" alt="Verification code. Use the refresh link for a different image." width="280" height="90" style="max-width:100%;height:auto">
        <a class="text-button" href="{{ route('visitor.challenge') }}">Get a different image</a>
        <label>Verification code<input name="answer" maxlength="6" pattern="[A-Za-z0-9]{6}" autocomplete="off" autocapitalize="characters" spellcheck="false" required></label>
        <button class="cta">CONTINUE TO STORE</button>
    </form>
</div>
@endsection
