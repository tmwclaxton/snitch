<?php

namespace App\Providers;

use App\Listeners\HandleStripeWebhook;
use App\Mail\PostalTransport;
use App\Support\ClientIp;
use App\Support\WorkOs\Ipv4CurlRequestClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Events\WebhookReceived;
use WorkOS\Client as WorkOsClient;

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
        $this->configureHttpClient();
        $this->configureWorkOsClient();
        $this->configureUrlGenerator();
        $this->configureRateLimiting();
        $this->registerPostalMailer();

        Event::listen(WebhookReceived::class, HandleStripeWebhook::class);
    }

    /**
     * Prefer IPv4 and bound connect time so dual-stack DNS cannot stall workers.
     */
    protected function configureHttpClient(): void
    {
        Http::globalOptions([
            'connect_timeout' => 3,
            'curl' => [
                \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4,
            ],
        ]);
    }

    /**
     * WorkOS SDK uses its own curl client (not Laravel HTTP) for token refresh.
     */
    protected function configureWorkOsClient(): void
    {
        WorkOsClient::setRequestClient(new Ipv4CurlRequestClient);
    }

    protected function registerPostalMailer(): void
    {
        Mail::extend('postal', function (): PostalTransport {
            return new PostalTransport(
                apiKey: (string) config('services.postal.key'),
                baseUrl: (string) config('services.postal.base_url'),
            );
        });
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
     *
     * Skip while the process is `wayfinder:generate` so TypeScript helpers stay
     * path-only (e.g. `/feed`). Otherwise Docker/CI builds bake APP_URL from
     * `.env.example` (`http://localhost`) into production assets.
     */
    protected function configureUrlGenerator(): void
    {
        if ($this->runningWayfinderGenerate()) {
            return;
        }

        $root = rtrim((string) config('app.url'), '/');

        if ($root !== '') {
            URL::forceRootUrl($root);
        }

        if (str_starts_with($root, 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Detect a real `php artisan wayfinder:generate` process via argv.
     * (PHPUnit's in-process artisan runner does not change argv.)
     */
    protected function runningWayfinderGenerate(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (is_string($arg) && str_contains($arg, 'wayfinder:generate')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guest throttles should key on Cloudflare's client IP when present.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('contact', function (Request $request) {
            return Limit::perMinute(10)->by(ClientIp::from($request));
        });

        RateLimiter::for('mcp', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: ClientIp::from($request));
        });

        RateLimiter::for('mcp-register', function (Request $request) {
            return Limit::perMinute(10)->by(ClientIp::from($request));
        });
    }
}
