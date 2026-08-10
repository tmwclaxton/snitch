<?php

namespace App\Services\TikHub;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TikHubClient
{
    /**
     * @var list<array{endpoint: string, platform: string|null, cogsUsd: float}>
     */
    private array $runCosts = [];

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], ?string $platform = null): array
    {
        $response = $this->http()->get($path, $query);

        if (! $response->successful()) {
            throw new RuntimeException('TikHub request failed ('.$response->status().'): '.$response->body());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('TikHub returned unexpected payload.');
        }

        $code = $json['code'] ?? $json['status_code'] ?? 0;
        if (is_numeric($code) && (int) $code !== 0 && (int) $code !== 200) {
            $message = (string) ($json['message'] ?? $json['msg'] ?? 'unknown error');

            throw new RuntimeException("TikHub API error ({$code}): {$message}");
        }

        $this->runCosts[] = [
            'endpoint' => $path,
            'platform' => $platform,
            'cogsUsd' => $this->estimateCogsUsd($platform),
        ];

        /** @var array<string, mixed> $json */
        return $json;
    }

    /**
     * @return list<array{endpoint: string, platform: string|null, cogsUsd: float}>
     */
    public function pullRunCosts(): array
    {
        $costs = $this->runCosts;
        $this->runCosts = [];

        return $costs;
    }

    public function configured(): bool
    {
        return (string) config('snitch.tikhub.api_key') !== '';
    }

    private function estimateCogsUsd(?string $platform): float
    {
        if ($platform !== null) {
            $floor = config("billing.vendors.tikhub.endpoints.{$platform}.floor_usd");

            if (is_numeric($floor)) {
                return (float) $floor;
            }
        }

        return (float) config('billing.vendors.tikhub.floor_usd', 0.001);
    }

    protected function http(): PendingRequest
    {
        $key = (string) config('snitch.tikhub.api_key');

        if ($key === '') {
            throw new RuntimeException('TIKHUB_API_KEY is not configured.');
        }

        return Http::baseUrl((string) config('snitch.tikhub.base_url'))
            ->withToken($key)
            ->acceptJson()
            ->timeout((int) config('snitch.tikhub.timeout', 60));
    }
}
