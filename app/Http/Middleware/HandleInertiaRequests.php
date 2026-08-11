<?php

namespace App\Http\Middleware;

use App\Services\Billing\PlanEntitlementService;
use App\Support\Seo;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // Closures are resolved lazily by Inertia's PropsResolver, so partial
        // reloads (e.g. defer() group requests) skip the DB work below when
        // the client did not ask for these keys.
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'subscription' => fn () => $user !== null
                ? app(PlanEntitlementService::class)->sharedSummary($user)
                : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'seo' => fn () => Seo::forRequest($request),
        ];
    }
}
