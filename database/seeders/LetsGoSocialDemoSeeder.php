<?php

namespace Database\Seeders;

use App\Enums\AnalysisStatus;
use App\Enums\MediaAvailability;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Enums\TrackedAccountKind;
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
            $this->seedRival($user, $rival);
        }
    }

    /**
     * @return list<array{
     *     handle: string,
     *     name: string,
     *     followers: int,
     *     captions: list<string>
     * }>
     */
    private function rivals(): array
    {
        return [
            [
                'handle' => 'socialchain',
                'name' => 'Social Chain',
                'followers' => 186400,
                'captions' => [
                    'Monday briefing for the feed #socialmedia #contentstrategy crusty hooks win',
                    'Carousel of this week’s briefs #agency #ukcreators',
                    'Still from the studio floor #behindthescenes',
                    'Reel cut on the beat #reels #socialfirst',
                ],
            ],
            [
                'handle' => 'wearesocial',
                'name' => 'We Are Social',
                'followers' => 412000,
                'captions' => [
                    'Culture report drop #wearesocial #culture',
                    'Static chart of save rate #insights',
                    'Sidecar walkthrough of the idea #howto',
                    'Hook test in 7 seconds #reels',
                ],
            ],
            [
                'handle' => 'later',
                'name' => 'Later',
                'followers' => 92800,
                'captions' => [
                    'Schedule the stills before the reel #later #planning',
                    'Hashtag set for a cafe client #hashtags #instagram',
                    'Link in bio is not a strategy #cta',
                    'Best time to post is when they already scroll #cadence',
                ],
            ],
        ];
    }

    /**
     * @param  array{
     *     handle: string,
     *     name: string,
     *     followers: int,
     *     captions: list<string>
     * }  $rival
     */
    private function seedRival(User $user, array $rival): void
    {
        $social = SocialAccount::query()->updateOrCreate(
            [
                'platform' => Platform::Instagram,
                'handle' => $rival['handle'],
            ],
            [
                'url' => 'https://instagram.com/'.$rival['handle'],
                'external_id' => 'demo-'.$rival['handle'],
                'avatar' => 'https://picsum.photos/seed/'.$rival['handle'].'/200',
                'display_name' => $rival['name'],
            ],
        );

        $account = TrackedAccount::query()->create([
            'user_id' => $user->id,
            'social_account_id' => $social->id,
            'kind' => TrackedAccountKind::Competitor,
            'platform' => Platform::Instagram,
            'handle' => $rival['handle'],
            'url' => $social->url,
            'external_id' => $social->external_id,
            'avatar' => $social->avatar,
            'display_name' => $rival['name'],
            'followers' => $rival['followers'],
            'last_synced_at' => now()->subHours(4),
            'last_sync_status' => 'success',
            'last_sync_error' => null,
        ]);

        $types = [PostType::Reel, PostType::Carousel, PostType::Image, PostType::Reel];

        foreach ($types as $index => $type) {
            $postedAt = now()->subDays($index)->setHour(9 + ($index * 3))->setMinute(15);
            $isReel = $type === PostType::Reel;
            $cover = 'https://picsum.photos/seed/'.$rival['handle'].$index.'/640/860';

            $post = Post::query()->updateOrCreate(
                [
                    'social_account_id' => $social->id,
                    'external_id' => 'demo-'.$rival['handle'].'-'.$index,
                ],
                [
                    'platform' => Platform::Instagram,
                    'type' => $type,
                    'url' => 'https://www.instagram.com/'.($isReel ? 'reel' : 'p').'/'.$rival['handle'].$index.'/',
                    'posted_at' => $postedAt,
                    'caption' => $rival['captions'][$index] ?? $rival['captions'][0],
                    'media_url' => $isReel ? 'https://cdn.example.com/'.$rival['handle'].$index.'.mp4' : $cover,
                    'cover_url' => $cover,
                    'media_availability' => MediaAvailability::Available,
                    'metrics' => [
                        'views' => 8000 + ($index * 1400),
                        'likes' => 420 + ($index * 80),
                        'comments' => 18 + $index,
                        'shares' => 9 + $index,
                    ],
                    'raw_payload' => [
                        'displayUrl' => $cover,
                        'source' => 'lets-go-social-demo',
                    ],
                ],
            );

            $post->load('analysis');

            if (! $isReel) {
                continue;
            }

            if ($post->analysis === null) {
                PostAnalysis::factory()->for($post)->create([
                    'status' => AnalysisStatus::Completed,
                    'hook' => 'Open on the brief, land on the proof.',
                    'concept' => 'Agency process as entertainment',
                    'topics' => ['social_media', 'agency'],
                    'cta' => 'Save this for the next content meeting',
                    'how_to_copy' => "1. Open on the messy brief.\n2. Cut to the still that proves it.\n3. End on the ask.",
                    'analyzed_at' => now(),
                ]);
            }

            if ($index === 0 && ! WinnerInsight::query()->where('user_id', $user->id)->where('post_id', $post->id)->exists()) {
                WinnerInsight::factory()->forPost($post, $user)->create([
                    'score' => 86.4,
                    'why' => 'High saves relative to views and a clear remake path.',
                    'how_to_copy' => "1. Open on the messy brief.\n2. Cut to the still that proves it.\n3. End on the ask.",
                ]);
            }
        }

        unset($account);
    }
}
