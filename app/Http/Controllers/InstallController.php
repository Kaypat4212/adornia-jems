<?php

namespace App\Http\Controllers;

use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\HomepageSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class InstallController extends Controller
{
    public function show(Request $request): Response
    {
        return response()
            ->view('install', ['enabled' => $this->enabled($request)])
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->enabled($request), 404);

        // The database cache table may not exist on a fresh install.
        $limiter = new RateLimiter(Cache::store('file'));
        $attempts = 'store-install-token:'.$request->ip();
        abort_if($limiter->tooManyAttempts($attempts, 5), 429);

        $token = $request->input('install_token');
        if (! is_string($token) || ! hash_equals(config('install.token'), $token)) {
            $limiter->hit($attempts, 60);

            return back()->withErrors(['install_token' => 'The setup token does not match.']);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['bail', 'required', 'string', 'confirmed', 'min:12',
                function (string $attribute, string $value, \Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail('Use a password of at most 72 bytes.');
                    }
                },
            ],
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $admin = $validator->validated();

        $lock = @fopen(storage_path('app/store-install.lock'), 'c');
        if ($lock === false) {
            return back()->withErrors(['install' => 'Make sure Laravel can write to its storage directory.']);
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return back()->withErrors(['install' => 'Setup is already running. Wait a moment and reload this page.']);
            }
            if ($this->installed()) {
                return redirect()->route('admin.login');
            }
            if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Store migrations failed.');
            }
            if (User::where('email', $admin['email'])->exists()) {
                return back()->withErrors(['email' => 'This address already has an account. Use a new address, or promote the existing account from the terminal.']);
            }

            DB::transaction(function () use ($admin): void {
                app(HomepageSeeder::class)->run();
                app(DeliverySeeder::class)->run();

                (new User)->forceFill($admin + ['is_admin' => true])->save();
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['install' => 'Setup could not finish. Check the database connection, permissions, and Laravel log before retrying.']);
        } finally {
            fclose($lock);
        }

        $limiter->clear($attempts);

        return redirect()->route('admin.login')->with('status', 'Adornia Jems is installed. Sign in with your new administrator account.');
    }

    private function enabled(Request $request): bool
    {
        $token = config('install.token');
        if (! is_string($token) || strlen($token) < 32 || (app()->isProduction() && ! $request->secure())) {
            return false;
        }
        try {
            return ! $this->installed();
        } catch (Throwable) {
            // Let the authenticated setup attempt surface a useful database error.
            return true;
        }
    }

    private function installed(): bool
    {
        return Schema::hasTable('users')
            && Schema::hasColumn('users', 'is_admin')
            && User::where('is_admin', true)->exists();
    }
}
