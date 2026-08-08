<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Enums\PostType;
use App\Services\Apify\PlatformAdapterManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:probe-apify {platform} {handle}')]
#[Description('Live Apify adapter probe for a platform handle (reel-only, recency-aware)')]
class ProbeApifyCommand extends Command
{
    public function handle(PlatformAdapterManager $adapters): int
    {
        if (! filter_var((string) env('SNITCH_LIVE_APIFY', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->warn('SNITCH_LIVE_APIFY is not enabled. Skipping live probe.');

            return self::SUCCESS;
        }

        $platform = Platform::tryFrom((string) $this->argument('platform'));

        if ($platform === null) {
            $this->error('Invalid platform. Use tiktok, instagram, facebook, linkedin, or youtube.');

            return self::FAILURE;
        }

        $handle = ltrim((string) $this->argument('handle'), '@');
        $recencyDays = max(1, (int) config('snitch.sync.recency_days', 30));
        $limit = min(3, max(1, (int) config('snitch.sync.posts_limit', 12)));
        $cutoff = CarbonImmutable::now()->subDays($recencyDays);

        $this->info("Apify probe assumptions: reel/short-video only; last {$recencyDays} days; limit={$limit}");
        if ($platform === Platform::Youtube) {
            $this->warn('YouTube: expect Shorts only. media_url may be a page URL (analysis needs MP4).');
        }

        $adapter = $adapters->for($platform);

        $profile = $adapter->resolveProfile($handle);
        $posts = $adapter->listRecentPosts($handle, $limit);

        $this->line(json_encode(['profile' => $profile, 'posts' => $posts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($posts === []) {
            $this->error('No posts returned.');

            return self::FAILURE;
        }

        $analyzable = PostType::analyzableValues();

        foreach ($posts as $index => $post) {
            foreach (['external_id', 'url', 'posted_at', 'type', 'media_url'] as $field) {
                if (blank($post[$field] ?? null)) {
                    $this->error("Post[{$index}] missing required field: {$field}");

                    return self::FAILURE;
                }
            }

            if (! in_array((string) $post['type'], $analyzable, true)) {
                $this->error("Post[{$index}] type must be reel/video, got {$post['type']}");

                return self::FAILURE;
            }

            $postedAt = CarbonImmutable::parse((string) $post['posted_at']);

            if ($postedAt->lt($cutoff)) {
                $this->error("Post[{$index}] older than {$recencyDays}-day recency window ({$postedAt->toIso8601String()}).");

                return self::FAILURE;
            }
        }

        $this->info('Apify probe passed (reel-like + recency).');

        return self::SUCCESS;
    }
}
