<?php

namespace App\Http\Middleware;

use App\Models\VisitorSetting;
use App\Services\VisitorTracking;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckNewVisitor
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_admin) {
            return $next($request);
        }
        $tracking = app(VisitorTracking::class);
        if (VisitorSetting::values()['captcha_enabled'] && ! $tracking->verified($request)) {
            return redirect()->route('visitor.challenge')->header('Cache-Control', 'no-store');
        }
        $tracking->record($request, $tracking->token($request));

        return $next($request)->header('Cache-Control', 'no-store, private');
    }
}
