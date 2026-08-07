<?php

namespace App\Jobs;

use App\Enums\PostType;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Services\Apify\PlatformAdapterManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncTrackedAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $trackedAccountId) {}

    public function handle(PlatformAdapterManager $adapters): void
    {
        $account = TrackedAccount::query()->find($this->trackedAccountId);

        if ($account === null) {
            return;
        }

        try {
            $adapter = $adapters->for($account->platform);
            $profile = $adapter->resolveProfile($account->handle);

            $account->fill([
                'url' => $profile['url'] ?: $account->url,
                'external_id' => $profile['external_id'] ?? $account->external_id,
                'avatar' => $profile['avatar'] ?? $account->avatar,
                'display_name' => $profile['display_name'] ?? $account->display_name,
                'last_synced_at' => now(),
            ])->save();

            $posts = $adapter->listRecentPosts($account->handle, 12);

            foreach ($posts as $payload) {
                if (blank($payload['url'])) {
                    continue;
                }

                $externalId = $payload['external_id'] ?? md5($payload['url']);

                $post = Post::query()->updateOrCreate(
                    [
                        'tracked_account_id' => $account->id,
                        'external_id' => $externalId,
                    ],
                    [
                        'user_id' => $account->user_id,
                        'platform' => $account->platform,
                        'type' => $payload['type'] ?? PostType::Image->value,
                        'url' => $payload['url'],
                        'posted_at' => $payload['posted_at'] ?? null,
                        'caption' => $payload['caption'] ?? null,
                        'media_url' => $payload['media_url'] ?? null,
                        'metrics' => $payload['metrics'] ?? [],
                        'raw_payload' => $payload['raw_payload'] ?? [],
                    ],
                );

                if ($post->wasRecentlyCreated && filled($post->media_url)) {
                    AnalyzePostJob::dispatch($post->id);
                }
            }
        } catch (Throwable $e) {
            Log::warning('SyncTrackedAccountJob failed', [
                'tracked_account_id' => $this->trackedAccountId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
