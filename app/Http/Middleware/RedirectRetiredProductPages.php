<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soft-retire product pages to the curated dashboard while keeping controllers wired
 * for Wayfinder and an easy rollback (remove this middleware from the route group).
 */
class RedirectRetiredProductPages
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
