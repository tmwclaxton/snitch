<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Services\Apify\PlatformAdapterManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:probe-apify {platform} {handle}')]
#[Description('Live Apify adapter probe for a platform handle')]
class ProbeApifyCommand extends Command
{
    public function handle(PlatformAdapterManager $adapters): int
    {
        if (! filter_var((string) env('SNITCH_LIVE_APIFY', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->warn('SNITCH_LIVE_APIFY is not enabled. Skipping live probe.');

            return self::SUCCESS;
        }

        $platform = Platform::from((string) $this->argument('platform'));
        $handle = (string) $this->argument('handle');
        $adapter = $adapters->for($platform);

        $profile = $adapter->resolveProfile($handle);
        $posts = $adapter->listRecentPosts($handle, 3);

        $this->line(json_encode(['profile' => $profile, 'posts' => $posts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($posts as $post) {
            foreach (['external_id', 'url', 'posted_at', 'type'] as $field) {
                if (blank($post[$field] ?? null)) {
                    $this->error("Missing required field: {$field}");

                    return self::FAILURE;
                }
            }
        }

        if ($posts === []) {
            $this->error('No posts returned.');

            return self::FAILURE;
        }

        $this->info('Apify probe passed.');

        return self::SUCCESS;
    }
}
