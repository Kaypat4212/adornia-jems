@extends('layouts.store')
@section('title', 'Account')
@section('content')
<div class="auth-card"><span class="eyebrow">Your Adornia world</span><h1 class="page-title">{{ match($mode) { 'register' => 'A little more you.', 'password.request' => 'Forgot your password?', 'password.reset' => 'A fresh start.', default => 'Welcome back.' } }}</h1>
<form method="post" action="{{ match($mode) { 'register' => '/register', 'password.request' => '/forgot-password', 'password.reset' => '/reset-password', default => '/login' } }}">@csrf
@if($mode === 'register')<label>Your name<input name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name"></label>@endif
@if($mode === 'password.reset')<input type="hidden" name="token" value="{{ $token }}">@endif
<label>Email address<input type="email" name="email" value="{{ old('email', request('email')) }}" required autocomplete="email"></label>
@if($mode !== 'password.request')<label>Password<input type="password" name="password" required autocomplete="{{ $mode === 'login' ? 'current-password' : 'new-password' }}" @if($mode !== 'login') minlength="8" @endif></label>@endif
@if(in_array($mode, ['register', 'password.reset']))<p>Use at least 8 characters, including letters and numbers.</p><label>Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password"></label>@endif
@if($mode === 'login')<label class="check"><input type="checkbox" name="remember" value="1"> Remember me</label>@endif
<button class="cta">{{ match($mode) { 'register' => 'CREATE ACCOUNT', 'password.request' => 'SEND RESET LINK', 'password.reset' => 'RESET PASSWORD', default => 'LOG IN' } }} ↗</button></form>
@if($mode === 'login')<p><a href="/forgot-password">Forgot password?</a></p><p>New here? <a href="/register">Create your account</a></p>@else<p>Already have an account? <a href="/login">Log in</a></p>@endif</div>
@endsection