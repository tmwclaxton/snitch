<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\TikHub\TikHubClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('snitch:probe-tikhub {--platform=* : Platforms to probe (instagram,tiktok,youtube,linkedin)} {--handle=nike : Default handle / channel} {--limit=12 : Recent posts to list}')]
#[Description('Live TikHub cost probe (resolve + list posts) for Nike-style handles; prints per-call COGS')]
class ProbeTikHubCommand extends Command
{
    /**
     * @var array<string, string>
     */
    private array $defaultHandles = [
        'instagram' => 'nike',
        'tiktok' => 'nike',
        'youtube' => 'nike',
        'linkedin' => 'nike',
    ];

    public function handle(PlatformAdapterManager $adapters, TikHubClient $client): int
    {
        if (! filter_var((string) env('SNITCH_LIVE_TIKHUB', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->warn('SNITCH_LIVE_TIKHUB is not enabled. Skipping live probe.');

            return self::SUCCESS;
        }

        if (! $client->configured()) {
            $this->error('TIKHUB_API_KEY is not configured.');

            return self::FAILURE;
        }

        $requested = array_values(array_filter($this->option('platform') ?: []));
        $platforms = $requested !== []
            ? $requested
            : ['instagram', 'tiktok', 'youtube', 'linkedin'];

        $overrideHandle = ltrim((string) $this->option('handle'), '@');
        $limit = max(1, min(24, (int) $this->option('limit')));

        $this->info('TikHub probe: platforms='.implode(',', $platforms)." limit={$limit}");

        $grandTotal = 0.0;
        $ok = true;

        foreach ($platforms as $platformName) {
            $platform = Platform::tryFrom($platformName);

            if ($platform === null || ! in_array($platform, [Platform::Instagram, Platform::TikTok, Platform::Youtube, Platform::LinkedIn], true)) {
                $this->error("Unsupported platform: {$platformName}");
                $ok = false;

                continue;
            }

            $adapter = $adapters->tikHubAdapter($platform);

            if ($adapter === null) {
                $this->error("No TikHub adapter for {$platformName}");
                $ok = false;

                continue;
            }

            $handle = $overrideHandle !== '' && $overrideHandle !== 'nike'
                ? $overrideHandle
                : ($this->defaultHandles[$platformName] ?? 'nike');

            $client->pullRunCosts();
            $this->line('');
            $this->info("--- {$platformName} @{$handle} ---");

            try {
                $profile = $adapter->resolveProfile($handle);
                $posts = $adapter->listRecentPosts($handle, $limit);
                $costs = $client->pullRunCosts();
                $platformTotal = array_sum(array_column($costs, 'cogsUsd'));
                $grandTotal += $platformTotal;

                $mediaOk = 0;
                foreach ($posts as $post) {
                    if (filled($post['media_url'] ?? null)) {
                        $mediaOk++;
                    }
                }

                $this->line(json_encode([
                    'profile' => [
                        'handle' => $profile['handle'] ?? null,
                        'display_name' => $profile['display_name'] ?? null,
                        'url' => $profile['url'] ?? null,
                    ],
                    'posts_count' => count($posts),
                    'media_url_present' => $mediaOk,
                    'calls' => $costs,
                    'platform_cogs_usd' => round($platformTotal, 6),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                if ($posts === []) {
                    $this->warn('No posts returned (endpoint or mapping may need retune).');
                }
            } catch (Throwable $exception) {
                $ok = false;
                $costs = $client->pullRunCosts();
                $grandTotal += array_sum(array_column($costs, 'cogsUsd'));
                $this->error($exception->getMessage());
                if ($costs !== []) {
                    $this->line(json_encode(['partial_calls' => $costs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        $this->line('');
        $this->info('Estimated sync COGS USD (from billing floors): '.round($grandTotal, 6));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
