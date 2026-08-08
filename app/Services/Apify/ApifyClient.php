<?php

namespace App\Services\Apify;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApifyClient
{
    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function runActor(string $actorId, array $input = []): array
    {
        $encodedActor = rawurlencode($actorId);
        $response = $this->http()->post("/acts/{$encodedActor}/run-sync-get-dataset-items", $input);

        return $this->datasetItemsFromResponse($actorId, $response);
    }

    /**
     * Run multiple actor sync requests concurrently (in-process Http::pool).
     *
     * Failed keys return an empty item list so callers can skip them.
     *
     * @param  array<array-key, array{actorId: string, input: array<string, mixed>}>  $jobs
     * @return array<array-key, list<array<string, mixed>>>
     */
    public function runActors(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }

        if (count($jobs) === 1) {
            $key = array_key_first($jobs);
            $job = $jobs[$key];

            try {
                return [$key => $this->runActor($job['actorId'], $job['input'])];
            } catch (RuntimeException) {
                return [$key => []];
            }
        }

        $token = (string) config('snitch.apify.token');

        if ($token === '') {
            throw new RuntimeException('APIFY_TOKEN is not configured.');
        }

        $baseUrl = (string) config('snitch.apify.base_url');
        $timeout = (int) config('snitch.apify.timeout', 180);

        $responses = Http::pool(function (Pool $pool) use ($jobs, $baseUrl, $token, $timeout): void {
            foreach ($jobs as $key => $job) {
                $encodedActor = rawurlencode($job['actorId']);
                $pool->as((string) $key)
                    ->baseUrl($baseUrl)
                    ->withQueryParameters(['token' => $token])
                    ->acceptJson()
                    ->timeout($timeout)
                    ->post("/acts/{$encodedActor}/run-sync-get-dataset-items", $job['input']);
            }
        });

        $out = [];

        foreach ($jobs as $key => $job) {
            $response = $responses[(string) $key] ?? null;

            if (! $response instanceof Response) {
                $out[$key] = [];

                continue;
            }

            try {
                $out[$key] = $this->datasetItemsFromResponse($job['actorId'], $response);
            } catch (RuntimeException) {
                $out[$key] = [];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function datasetItemsFromResponse(string $actorId, Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException("Apify actor {$actorId} failed: ".$response->body());
        }

        $items = $response->json();

        if (! is_array($items)) {
            throw new RuntimeException("Apify actor {$actorId} returned unexpected payload.");
        }

        /** @var list<array<string, mixed>> $items */
        return array_values(array_filter($items, 'is_array'));
    }

    protected function http(): PendingRequest
    {
        $token = (string) config('snitch.apify.token');

        if ($token === '') {
            throw new RuntimeException('APIFY_TOKEN is not configured.');
        }

        return Http::baseUrl((string) config('snitch.apify.base_url'))
            ->withQueryParameters(['token' => $token])
            ->acceptJson()
            ->timeout((int) config('snitch.apify.timeout', 180));
    }
}
