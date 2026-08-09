<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\WorkOS\WorkOS;
use Throwable;
use WorkOS\UserManagement;

class WarmWorkOsJwkCommand extends Command
{
    protected $signature = 'snitch:warm-workos-jwk';

    protected $description = 'Fetch and cache the WorkOS JWKS used by ValidateSessionWithWorkOS';

    public function handle(): int
    {
        if (! config('services.workos.client_id') || ! config('services.workos.secret')) {
            $this->warn('WorkOS is not configured; skipping JWKS warm.');

            return self::SUCCESS;
        }

        try {
            WorkOS::configure();

            $url = (new UserManagement)->getJwksUrl((string) config('services.workos.client_id'));
            $response = Http::withOptions([
                'curl' => [
                    \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4,
                ],
            ])
                ->connectTimeout(3)
                ->timeout(10)
                ->get($url);

            if (! $response->successful()) {
                $this->error('WorkOS JWKS request failed with HTTP '.$response->status());

                return self::FAILURE;
            }

            /** @var array<string, mixed> $jwk */
            $jwk = $response->json();

            if ($jwk === []) {
                $this->error('WorkOS JWKS response was empty.');

                return self::FAILURE;
            }

            Cache::put('workos:jwk', $jwk, now()->addHours(12));
            $this->info('WorkOS JWKS cached for 12 hours.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Failed to warm WorkOS JWKS: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
