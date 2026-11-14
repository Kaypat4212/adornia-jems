<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function form(Request $request): View
    {
        return view('auth', ['mode' => $request->route()->getName(), 'token' => $request->route('token')]);
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:254|unique:users', 'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()]]);
        Auth::login(User::create($data));
        $request->session()->regenerate();
        app(\App\Services\StoreEmail::class)->welcome($request->user());

        return redirect()->intended('/account');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($data, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'The email or password is incorrect.'])->onlyInput('email');
        }
        $request->session()->regenerate();

        return redirect()->intended($request->user()->is_admin ? ($request->user()->must_change_password ? '/admin/credentials' : '/admin') : '/account');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function forgot(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);
        app(\App\Services\EmailConfiguration::class)->apply();
        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'If an account matches this email, a reset link has been sent.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()]]);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset ? redirect('/login')->with('status', __($status)) : back()->withErrors(['email' => __($status)]);
    }

    public function account(Request $request): View
    {
        return view('account', ['user' => $request->user(), 'refundAccounts' => \App\Models\RefundAccount::where('user_id', $request->user()->id)->latest()->get(), 'orders' => \App\Models\Order::where('user_id', $request->user()->id)->latest()->paginate(10)]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['avatar_upload' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048', 'remove_avatar' => 'nullable|boolean', 'name' => 'required|string|max:100', 'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:1000', 'current_password' => 'required_with:password|current_password', 'password' => ['nullable', 'confirmed', PasswordRule::min(8)->letters()->numbers()]]);
        $profile = collect($data)->except(['current_password', 'password', 'avatar_upload', 'remove_avatar'])->all();
        if ($request->boolean('remove_avatar')) {
            $profile['avatar'] = null;
        }
        if ($request->hasFile('avatar_upload')) {
            $profile['avatar'] = $request->file('avatar_upload')->store('avatars', 'public');
        }
        $oldAvatar = $request->user()->avatar;
        $request->user()->forceFill($profile)->save();
        if ($oldAvatar && array_key_exists('avatar', $profile) && $oldAvatar !== $profile['avatar']) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($oldAvatar);
        }
        if ($request->filled('password')) {
            $request->user()->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();
        }

        return back()->with('status', 'Your profile has been saved.');
    }
}
