<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', $request->cookie('appearance') ?? 'system');
        // Logged-in app is pinned to Caution Tape night. Appearance cookie still
        // exists so the setting can come back; it must not flip the app to cream.
        View::share('appNight', $request->user() !== null);

        return $next($request);
    }
}
