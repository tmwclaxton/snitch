<?php

namespace App\Services\Apify;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ApifyClient
{
    /**
     * @var list<array{actorId: string, usageTotalUsd: float|null, runId: string|null}>
     */
    private array $runCosts = [];

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function runActor(string $actorId, array $input = []): array
    {
        return $this->runActorDetailed($actorId, $input)['items'];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{items: list<array<string, mixed>>, usageTotalUsd: float|null, runId: string|null}
     */
    public function runActorDetailed(string $actorId, array $input = []): array
    {
        $encodedActor = rawurlencode($actorId);
        $wait = max(30, min(300, (int) config('snitch.apify.timeout', 180)));

        $runResponse = $this->http()->post("/acts/{$encodedActor}/runs?waitForFinish={$wait}", $input);

        if (! $runResponse->successful()) {
            throw new RuntimeException("Apify actor {$actorId} failed: ".$runResponse->body());
        }

        $run = $runResponse->json('data') ?? $runResponse->json();

        if (! is_array($run)) {
            throw new RuntimeException("Apify actor {$actorId} returned unexpected run payload.");
        }

        $runId = isset($run['id']) && is_string($run['id']) ? $run['id'] : null;
        $usageTotalUsd = $this->extractUsageTotalUsd($run);

        // First response after finish can show preliminary costs; refresh once when possible.
        if ($runId !== null && ($usageTotalUsd === null || $usageTotalUsd <= 0)) {
            usleep(250_000);
            $refreshed = $this->http()->get("/actor-runs/{$runId}");

            if ($refreshed->successful()) {
                $runBody = $refreshed->json('data') ?? $refreshed->json();

                if (is_array($runBody)) {
                    $usageTotalUsd = $this->extractUsageTotalUsd($runBody) ?? $usageTotalUsd;
                    $run = $runBody;
                }
            }
        }

        $datasetId = $run['defaultDatasetId'] ?? null;

        if (! is_string($datasetId) || $datasetId === '') {
            throw new RuntimeException("Apify actor {$actorId} finished without a dataset id.");
        }

        $itemsResponse = $this->http()->get("/datasets/{$datasetId}/items", [
            'clean' => 1,
            'format' => 'json',
        ]);

        $items = $this->datasetItemsFromResponse($actorId, $itemsResponse);

        $this->runCosts[] = [
            'actorId' => $actorId,
            'usageTotalUsd' => $usageTotalUsd,
            'runId' => $runId,
        ];

        return [
            'items' => $items,
            'usageTotalUsd' => $usageTotalUsd,
            'runId' => $runId,
        ];
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
            } catch (Throwable) {
                return [$key => []];
            }
        }

        $token = (string) config('snitch.apify.token');

        if ($token === '') {
            throw new RuntimeException('APIFY_TOKEN is not configured.');
        }

        $baseUrl = (string) config('snitch.apify.base_url');
        $timeout = (int) config('snitch.apify.timeout', 180);
        $wait = max(30, min(300, $timeout));

        $responses = Http::pool(function (Pool $pool) use ($jobs, $baseUrl, $token, $timeout, $wait): void {
            foreach ($jobs as $key => $job) {
                $encodedActor = rawurlencode($job['actorId']);
                $pool->as((string) $key)
                    ->baseUrl($baseUrl)
                    ->withToken($token)
                    ->acceptJson()
                    ->timeout($timeout)
                    ->post("/acts/{$encodedActor}/runs?waitForFinish={$wait}", $job['input']);
            }
        });

        $out = [];

        foreach ($jobs as $key => $job) {
            $response = $responses[(string) $key] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                $out[$key] = [];

                continue;
            }

            try {
                $run = $response->json('data') ?? $response->json();

                if (! is_array($run)) {
                    $out[$key] = [];

                    continue;
                }

                $runId = isset($run['id']) && is_string($run['id']) ? $run['id'] : null;
                $usageTotalUsd = $this->extractUsageTotalUsd($run);
                $datasetId = $run['defaultDatasetId'] ?? null;

                if (! is_string($datasetId) || $datasetId === '') {
                    $out[$key] = [];

                    continue;
                }

                $itemsResponse = $this->http()->get("/datasets/{$datasetId}/items", [
                    'clean' => 1,
                    'format' => 'json',
                ]);

                $out[$key] = $this->datasetItemsFromResponse($job['actorId'], $itemsResponse);

                $this->runCosts[] = [
                    'actorId' => $job['actorId'],
                    'usageTotalUsd' => $usageTotalUsd,
                    'runId' => $runId,
                ];
            } catch (Throwable) {
                $out[$key] = [];
            }
        }

        return $out;
    }

    /**
     * @return list<array{actorId: string, usageTotalUsd: float|null, runId: string|null}>
     */
    public function pullRunCosts(): array
    {
        $costs = $this->runCosts;
        $this->runCosts = [];

        return $costs;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function extractUsageTotalUsd(array $run): ?float
    {
        $raw = $run['usageTotalUsd'] ?? null;

        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function datasetItemsFromResponse(string $actorId, Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException("Apify actor {$actorId} dataset failed: ".$response->body());
        }

        $items = $response->json();

        if (! is_array($items)) {
            throw new RuntimeException("Apify actor {$actorId} returned unexpected dataset payload.");
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
            ->withToken($token)
            ->acceptJson()
            ->timeout((int) config('snitch.apify.timeout', 180));
    }
}
