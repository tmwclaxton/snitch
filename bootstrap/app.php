<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Seo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust proto/port/for/prefix for Cloudflare tunnels, but never X-Forwarded-Host.
        // Client-controlled Host forwarding rewrote absolute URLs and redirects in production.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->validateCsrfTokens(except: [
            'stripe/*',
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, mixed $exception, Request $request) {
            if ($response->getStatusCode() !== 404 || $request->expectsJson()) {
                return $response;
            }

            // Unmatched routes skip the web middleware group, so share SEO (and
            // minimal shell props) here for first-byte meta on the NotFound page.
            Inertia::setRootView('app');
            Inertia::share([
                'name' => config('app.name'),
                'auth' => [
                    'user' => $request->user(),
                ],
                'subscription' => null,
                'sidebarOpen' => true,
                'seo' => Seo::forRequest($request),
            ]);

            return Inertia::render('errors/NotFound')
                ->toResponse($request)
                ->setStatusCode(404);
        });
    })->create();
