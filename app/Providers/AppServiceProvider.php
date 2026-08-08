<?php

namespace App\Providers;

use App\Support\ClientIp;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureUrlGenerator();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Keep absolute URLs on the configured APP_URL host (ignore spoofed forwarded Host).
     */
    protected function configureUrlGenerator(): void
    {
        $root = rtrim((string) config('app.url'), '/');

        if ($root !== '') {
            URL::forceRootUrl($root);
        }

        if (str_starts_with($root, 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Guest throttles should key on Cloudflare's client IP when present.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(10)->by(ClientIp::from($request));
        });
    }
}
