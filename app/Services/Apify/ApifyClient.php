<?php

namespace App\Services\Apify;

use Illuminate\Http\Client\PendingRequest;
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
