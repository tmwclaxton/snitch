<?php

namespace App\Services\Tracking;

use App\Enums\Platform;
use App\Models\FollowerSnapshot;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Services\Apify\PlatformAdapterManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FollowerCountRefresher
{
    public function __construct(private PlatformAdapterManager $adapters) {}

    /**
     * Social accounts that still have a tracker and no fresh profile snapshot.
     *
     * @return list<int>
     */
    public function dueSocialAccountIds(): array
    {
        $cutoff = $this->cutoffDate();

        return SocialAccount::query()
            ->where('platform', Platform::Instagram)
            ->whereHas('trackedAccounts')
            ->whereDoesntHave('followerSnapshots', function ($query) use ($cutoff): void {
                $query->whereDate('captured_on', '>=', $cutoff)
                    ->where('source', FollowerSnapshotRecorder::SOURCE_PROFILE);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Every Instagram social account that still has a tracker, including those
     * with a profile snapshot today. Used by --force.
     *
     * @return list<int>
     */
    public function allTrackedInstagramSocialAccountIds(): array
    {
        return SocialAccount::query()
            ->where('platform', Platform::Instagram)
            ->whereHas('trackedAccounts')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function refresh(SocialAccount $social, bool $force = false): bool
    {
        $social->loadMissing('trackedAccounts');

        if ($social->trackedAccounts->isEmpty()) {
            return false;
        }

        $today = $social->followerSnapshots()
            ->whereDate('captured_on', CarbonImmutable::now()->toDateString())
            ->first();

        $todayIsFresh = $today !== null
            && $today->source === FollowerSnapshotRecorder::SOURCE_PROFILE;

        if ($todayIsFresh && ! $force) {
            $this->seedMissingTrackers($social->id);

            return false;
        }

        $cutoff = $this->cutoffDate();
        $recentFresh = $social->followerSnapshots()
            ->whereDate('captured_on', '>=', $cutoff)
            ->where('source', FollowerSnapshotRecorder::SOURCE_PROFILE)
            ->exists();

        if ($recentFresh && ! $force) {
            // A second tracker of an already-refreshed account must still inherit
            // the latest known count without paying for another profile scrape.
            $this->seedMissingTrackers($social->id);

            return false;
        }

        $sample = $social->trackedAccounts->first();

        if ($sample === null) {
            return false;
        }

        try {
            $profile = $this->resolveProfile($social, $sample);
        } catch (Throwable) {
            return false;
        }

        $followers = $this->followersFromProfile($profile);

        if ($followers === null) {
            return false;
        }

        $this->propagate($social->id, $followers);
        app(FollowerSnapshotRecorder::class)->record($social->id, $followers);

        return true;
    }

    /**
     * Latest observed follower count for a social account (snapshot, else any tracker).
     */
    public function latestKnown(int $socialAccountId): ?int
    {
        $fromSnapshot = FollowerSnapshot::query()
            ->where('social_account_id', $socialAccountId)
            ->orderByDesc('captured_on')
            ->orderByDesc('id')
            ->value('followers');

        if (is_numeric($fromSnapshot)) {
            return max(0, (int) $fromSnapshot);
        }

        $fromTracker = TrackedAccount::query()
            ->where('social_account_id', $socialAccountId)
            ->whereNotNull('followers')
            ->orderByDesc('updated_at')
            ->value('followers');

        return is_numeric($fromTracker) ? max(0, (int) $fromTracker) : null;
    }

    /**
     * Copy the latest known count onto every tracker for this social account.
     */
    public function propagate(int $socialAccountId, int $followers): void
    {
        TrackedAccount::query()
            ->where('social_account_id', $socialAccountId)
            ->update(['followers' => max(0, $followers)]);
    }

    /**
     * Fill null tracker follower columns from the latest known value.
     *
     * @return int Number of trackers updated
     */
    public function seedMissingTrackers(int $socialAccountId): int
    {
        $latest = $this->latestKnown($socialAccountId);

        if ($latest === null) {
            return 0;
        }

        return TrackedAccount::query()
            ->where('social_account_id', $socialAccountId)
            ->whereNull('followers')
            ->update(['followers' => $latest]);
    }

    /**
     * Seed a newly created tracker from any known count for its social account.
     */
    public function seedTracker(TrackedAccount $account): void
    {
        if ($account->followers !== null || $account->social_account_id === null) {
            return;
        }

        $latest = $this->latestKnown((int) $account->social_account_id);

        if ($latest === null) {
            return;
        }

        $account->forceFill(['followers' => $latest])->save();
    }

    /**
     * Earliest captured_on that still counts as "recent". Calendar-day based and
     * inclusive of today, so interval 1 means "already captured today" and a
     * snapshot taken mid-week never blocks the next scheduled run for 2 weeks.
     */
    private function cutoffDate(): string
    {
        $days = max(1, (int) config('snitch.followers.refresh_interval_days', 1));

        return CarbonImmutable::now()->startOfDay()->subDays($days - 1)->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveProfile(SocialAccount $social, TrackedAccount $sample): array
    {
        $primaryError = null;

        try {
            $profile = $this->adapters->for($social->platform)->resolveProfile($sample->handle);
            if ($this->followersFromProfile($profile) !== null) {
                return $profile;
            }
        } catch (Throwable $exception) {
            $primaryError = $exception;
            Log::warning('Follower refresh failed', [
                'social_account_id' => $social->id,
                'platform' => $social->platform->value,
                'handle' => $social->handle,
                'driver' => $this->adapters->driverFor($social->platform),
                'message' => $exception->getMessage(),
            ]);
        }

        if ($this->adapters->driverFor($social->platform) === 'tikhub') {
            try {
                $profile = $this->adapters->apifyAdapter($social->platform)->resolveProfile($sample->handle);
                if ($this->followersFromProfile($profile) !== null) {
                    return $profile;
                }
            } catch (Throwable $exception) {
                Log::warning('Follower refresh Apify fallback failed', [
                    'social_account_id' => $social->id,
                    'platform' => $social->platform->value,
                    'handle' => $social->handle,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        throw $primaryError ?? new RuntimeException('Follower refresh returned no count.');
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
