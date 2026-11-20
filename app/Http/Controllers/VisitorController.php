<?php

namespace App\Http\Controllers;

use App\Models\Visitor;
use App\Models\VisitorAlert;
use App\Models\VisitorSetting;
use App\Services\VisitorTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class VisitorController extends Controller
{
    public function challenge(Request $request): Response
    {
        abort_unless(function_exists('imagecreatetruecolor'), 503, 'Visitor verification is unavailable. Contact the store administrator.');
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $answer = '';
        $image = imagecreatetruecolor(280, 90);
        imagefill($image, 0, 0, imagecolorallocate($image, 247, 245, 239));
        for ($index = 0; $index < 6; $index++) {
            $letter = $alphabet[random_int(0, strlen($alphabet) - 1)];
            $answer .= $letter;
            $glyph = imagecreatetruecolor(12, 20);
            imagefill($glyph, 0, 0, imagecolorallocate($glyph, 247, 245, 239));
            imagestring($glyph, 5, 1, 2, $letter, imagecolorallocate($glyph, 40, 45, 35));
            imagecopyresampled($image, $glyph, 20 + $index * 40, random_int(15, 30), 0, 0, 26, 42, 12, 20);
        }
        for ($index = 0; $index < 5; $index++) {
            imageline($image, random_int(0, 280), random_int(0, 90), random_int(0, 280), random_int(0, 90), imagecolorallocate($image, 160, 150, 130));
        }
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        $request->session()->put('visitor_captcha', [
            'hash' => hash_hmac('sha256', $answer, config('app.key')), 'expires' => time() + 300,
        ]);
        app(VisitorTracking::class)->token($request);

        return response()->view('visitor-challenge', ['captchaImage' => base64_encode($png)])
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function image(): RedirectResponse
    {
        return redirect()->route('visitor.challenge')->header('Cache-Control', 'no-store, private');
    }

    public function verify(Request $request, VisitorTracking $tracking): RedirectResponse
    {
        $challenge = $request->session()->pull('visitor_captcha');
        $answer = $request->input('answer');
        if (! $challenge || $challenge['expires'] < time() || ! is_string($answer)
            || ! hash_equals($challenge['hash'], hash_hmac('sha256', strtoupper(trim($answer)), config('app.key')))) {
            return redirect()->route('visitor.challenge')->withErrors(['answer' => 'The code is incorrect or has expired. Try the new image.']);
        }
        $tracking->record($request, $tracking->token($request), verified: true);

        return redirect('/');
    }

    public function heartbeat(Request $request, VisitorTracking $tracking): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:200', 'regex:~^/(?:products/[0-9]+)?$~']]);
        if ($request->user()?->is_admin) {
            return response()->json(['ok' => true]);
        }
        abort_if(VisitorSetting::values()['captcha_enabled'] && ! $tracking->verified($request), 403);
        $tracking->record($request, $tracking->token($request), path: $data['path']);

        return response()->json(['ok' => true]);
    }

    public function index(): View
    {
        return view('admin.visitors', [
            'settings' => VisitorSetting::values(), 'visitors' => Visitor::orderByDesc('last_seen_at')->paginate(30),
            'active' => Visitor::where('last_seen_at', '>=', now()->subSeconds(90))->count(),
            'alerts' => VisitorAlert::latest()->limit(20)->get(),
        ]);
    }

    public function live(): JsonResponse
    {
        return response()->json(['active' => Visitor::where('last_seen_at', '>=', now()->subSeconds(90))->count(),
            'visitors' => Visitor::where('last_seen_at', '>=', now()->subSeconds(90))->latest('last_seen_at')->limit(50)->get()->map(fn (Visitor $visitor) => [
                'id' => $visitor->id, 'ip' => $visitor->maskedIp(), 'path' => $visitor->path,
                'user' => $visitor->user_id ? 'Account #'.$visitor->user_id : 'Guest',
                'last_seen' => $visitor->last_seen_at->toIso8601String(),
            ])])->header('Cache-Control', 'no-store, private');
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => 'required|current_password', 'captcha_enabled' => 'nullable|boolean',
            'email_enabled' => 'nullable|boolean', 'telegram_enabled' => 'nullable|boolean',
            'telegram_token' => ['nullable', 'string', 'regex:/^[0-9]+:[A-Za-z0-9_-]+$/', 'max:256'],
            'telegram_chat' => ['nullable', 'string', 'regex:/^(?:-?[0-9]+|@[A-Za-z][A-Za-z0-9_]{4,})$/', 'max:100'],
            'loader' => 'required|in:bar,ring,dots,pulse,diamond,none',
        ]);
        if ($request->boolean('captcha_enabled') && ! function_exists('imagecreatetruecolor')) {
            return back()->withErrors(['captcha_enabled' => 'Enable the PHP GD extension before enabling CAPTCHA.']);
        }
        $data['telegram_token'] = ($data['telegram_token'] ?? null) ?: VisitorSetting::values()['telegram_token'];
        if ($request->boolean('telegram_enabled') && (! $data['telegram_token'] || ! filled($data['telegram_chat'] ?? null))) {
            return back()->withErrors(['telegram_token' => 'Add a bot token and chat ID before enabling Telegram.']);
        }
        unset($data['current_password']);
        foreach (['captcha_enabled', 'email_enabled', 'telegram_enabled'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        VisitorSetting::updateOrCreate(['id' => 1], ['content' => $data]);

        return back()->with('status', 'Visitor settings and loader saved.');
    }
}
