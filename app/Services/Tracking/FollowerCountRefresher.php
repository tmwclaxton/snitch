<?php

namespace App\Services\Tracking;

use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Services\Apify\PlatformAdapterManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FollowerCountRefresher
{
    public function __construct(private PlatformAdapterManager $adapters) {}

    /**
     * Social accounts that still have a tracker and no recent follower snapshot.
     *
     * @return list<int>
     */
    public function dueSocialAccountIds(): array
    {
        $cutoff = $this->cutoffDate();

        return SocialAccount::query()
            ->whereHas('trackedAccounts')
            ->whereDoesntHave('followerSnapshots', function ($query) use ($cutoff): void {
                $query->whereDate('captured_on', '>=', $cutoff);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function refresh(SocialAccount $social): bool
    {
        $social->loadMissing('trackedAccounts');

        if ($social->trackedAccounts->isEmpty()) {
            return false;
        }

        $cutoff = $this->cutoffDate();
        $recent = $social->followerSnapshots()
            ->whereDate('captured_on', '>=', $cutoff)
            ->exists();

        if ($recent) {
            return false;
        }

        $sample = $social->trackedAccounts->first();

        if ($sample === null) {
            return false;
        }

        try {
            $profile = $this->adapters->for($social->platform)->resolveProfile($sample->handle);
        } catch (Throwable $exception) {
            Log::warning('Follower refresh failed', [
                'social_account_id' => $social->id,
                'platform' => $social->platform->value,
                'handle' => $social->handle,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        $followers = $this->followersFromProfile($profile);

        if ($followers === null) {
            return false;
        }

        TrackedAccount::query()
            ->where('social_account_id', $social->id)
            ->update(['followers' => $followers]);

        app(FollowerSnapshotRecorder::class)->record($social->id, $followers);

        return true;
    }

    private function cutoffDate(): string
    {
        $days = max(1, (int) config('snitch.followers.refresh_interval_days', 6));

        return CarbonImmutable::now()->subDays($days)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function followersFromProfile(array $profile): ?int
    {
        if (! isset($profile['followers']) || ! is_numeric($profile['followers'])) {
            return null;
        }

        return max(0, (int) $profile['followers']);
    }
}
