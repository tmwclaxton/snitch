<?php

namespace App\Services\Automations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CursorAutomationsClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{cursor_run_id: ?string, cursor_background_composer_id: ?string, raw: array<string, mixed>}
     */
    public function trigger(string $webhookUrl, string $webhookToken, array $payload): array
    {
        if (! $this->isConfiguredPair($webhookUrl, $webhookToken)) {
            throw new RuntimeException('Cursor automation webhook is not configured.');
        }

        try {
            $response = Http::withToken($webhookToken)
                ->acceptJson()
                ->asJson()
                ->timeout(30)
                ->post($webhookUrl, $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Cursor automation webhook connection failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'Cursor automation webhook failed with HTTP '.$response->status().'.',
            );
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        $runUuid = isset($json['runUuid']) && is_string($json['runUuid']) && $json['runUuid'] !== ''
            ? $json['runUuid']
            : null;
        $composerId = isset($json['backgroundComposerId']) && is_string($json['backgroundComposerId']) && $json['backgroundComposerId'] !== ''
            ? $json['backgroundComposerId']
            : null;

        if ($runUuid === null && $composerId === null) {
            // Some responses nest ids; accept common alternates.
            $runUuid = isset($json['run_id']) && is_string($json['run_id']) ? $json['run_id'] : null;
            $composerId = isset($json['background_composer_id']) && is_string($json['background_composer_id'])
                ? $json['background_composer_id']
                : $composerId;
        }

        return [
            'cursor_run_id' => $runUuid,
            'cursor_background_composer_id' => $composerId,
            'raw' => $json,
        ];
    }

    public function isConfiguredPair(?string $url, ?string $token): bool
    {
        $url = trim((string) $url);
        $token = trim((string) $token);

        if ($url === '' || $token === '') {
            return false;
        }

        if ($this->looksLikePlaceholder($url) || $this->looksLikePlaceholder($token)) {
            return false;
        }

        if (! str_starts_with($url, 'https://')) {
            return false;
        }

        return true;
    }

    public function looksLikePlaceholder(string $value): bool
    {
        $lower = strtolower($value);

        foreach (['your_', 'example.com', 'changeme', 'placeholder', 'xxx', '<', 'todo'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
