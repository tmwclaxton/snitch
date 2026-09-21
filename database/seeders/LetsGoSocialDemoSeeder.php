<?php

namespace Database\Seeders;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Jobs\SyncTrackedAccountJob;
use App\Models\BrandProfile;
use App\Models\Post;
use App\Models\PostAnalysis;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use Illuminate\Database\Seeder;

class LetsGoSocialDemoSeeder extends Seeder
{
    public const OWNER_EMAIL = 'tmwclaxton@gmail.com';

    public function run(): void
    {
        $user = User::query()->where('email', self::OWNER_EMAIL)->first();

        if ($user === null) {
            return;
        }

        $this->forgetPlaceholderCorpus();
        $user->trackedAccounts()->delete();

        BrandProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => "Let's Go Social",
                'website' => 'https://www.letsgosocial.co.uk',
                'description' => 'UK social studio. Content, community, and paid social for brands that want to be found.',
                'competitor_brief' => 'UK social media agencies and scheduling tools that post reels, carousels, and stills',
                'influencer_brief' => null,
                'own_handles' => [
                    'instagram' => '@letsgosocialuk',
                    'tiktok' => null,
                    'facebook' => null,
                    'linkedin' => null,
                    'youtube' => null,
                ],
            ],
        );

        foreach ($this->rivals() as $rival) {
            $this->trackRival($user, $rival);
        }
    }

    /**
     * @return list<array{handle: string, name: string}>
     */
    private function rivals(): array
    {
        return [
            ['handle' => 'socialchain', 'name' => 'Social Chain'],
            ['handle' => 'wearesocial', 'name' => 'We Are Social'],
            ['handle' => 'later', 'name' => 'Later'],
        ];
    }

    /**
     * @param  array{handle: string, name: string}  $rival
     */
    private function trackRival(User $user, array $rival): void
    {
        $account = TrackedAccount::query()->create([
            'user_id' => $user->id,
            'kind' => TrackedAccountKind::Competitor,
            'platform' => Platform::Instagram,
            'handle' => $rival['handle'],
            'url' => 'https://instagram.com/'.$rival['handle'],
            'display_name' => $rival['name'],
            'last_synced_at' => null,
            'last_sync_status' => 'pending',
            'last_sync_error' => null,
        ]);

        $account->markSyncRunning();
        SyncTrackedAccountJob::dispatch($account->id, force: true);
    }

    private function forgetPlaceholderCorpus(): void
    {
        $postIds = Post::query()
            ->where('external_id', 'like', 'demo-%')
            ->pluck('id');

        if ($postIds->isEmpty()) {
            return;
        }

        WinnerInsight::query()->whereIn('post_id', $postIds)->delete();
        PostAnalysis::query()->whereIn('post_id', $postIds)->delete();
        Post::query()->whereIn('id', $postIds)->delete();

        SocialAccount::query()
            ->where('external_id', 'like', 'demo-%')
            ->update(['external_id' => null]);
    }
}
