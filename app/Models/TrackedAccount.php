<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Services\Dashboard\DashboardCache;
use App\Services\SocialAccounts\SocialAccountResolver;
use App\Services\Tracking\FollowerCountRefresher;
use Carbon\CarbonInterface;
use Database\Factories\TrackedAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'social_account_id',
    'platform',
    'kind',
    'is_own_account',
    'handle',
    'url',
    'external_id',
    'avatar',
    'avatar_source_url',
    'display_name',
    'followers',
    'fit_reason',
    'last_synced_at',
    'last_sync_status',
    'last_sync_error',
])]
class TrackedAccount extends Model
{
    /** @use HasFactory<TrackedAccountFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Upsert including soft-deleted rows, restoring when the match was trashed.
     *
     * Re-adding a removed handle must not collide with the unique
     * (user_id, platform, handle) index.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public static function updateOrRestore(array $attributes, array $values = []): static
    {
        $account = static::withTrashed()->updateOrCreate($attributes, $values);

        if ($account->trashed()) {
            $account->restore();
        }

        return $account;
    }

    protected static function booted(): void
    {
        static::creating(function (TrackedAccount $account): void {
            if ($account->social_account_id !== null) {
                if ($account->followers === null) {
                    $account->followers = app(FollowerCountRefresher::class)
                        ->latestKnown((int) $account->social_account_id);
                }

                return;
            }

            $resolver = app(SocialAccountResolver::class);
            $social = $resolver->resolve(
                platform: $account->platform instanceof Platform
                    ? $account->platform
                    : (string) $account->platform,
                handle: (string) $account->handle,
                externalId: filled($account->external_id) ? (string) $account->external_id : null,
                attributes: [
                    'url' => $account->url,
                    'avatar' => $account->avatar,
                    'display_name' => $account->display_name,
                ],
            );

            $account->social_account_id = $social->id;
            $account->handle = $social->handle;
            $account->external_id = $account->external_id ?: $social->external_id;
            $account->url = $account->url ?: $social->url;
            $account->avatar = $account->avatar ?: $social->avatar;
            $account->display_name = $account->display_name ?: $social->display_name;

            if ($account->followers === null) {
                $account->followers = app(FollowerCountRefresher::class)->latestKnown($social->id);
            }
        });

        static::created(function (TrackedAccount $account): void {
            // Safety net for updateOrCreate / factory paths that left followers null.
            if ($account->followers === null && $account->social_account_id !== null) {
                app(FollowerCountRefresher::class)->seedTracker($account);
            }
        });

        static::updating(function (TrackedAccount $account): void {
            if (! $account->isDirty(['handle', 'external_id', 'url', 'avatar', 'display_name', 'platform'])) {
                return;
            }

            if ($account->social_account_id === null) {
                return;
            }

            $social = $account->socialAccount;
            if ($social === null) {
                return;
            }

            $social->fill(array_filter([
                'handle' => app(SocialAccountResolver::class)->normalizeHandle((string) $account->handle),
                'external_id' => filled($account->external_id) ? (string) $account->external_id : $social->external_id,
                'url' => $account->url,
                'avatar' => $account->avatar,
                'display_name' => $account->display_name,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''))->save();
        });

        static::saved(function (TrackedAccount $account): void {
            if ($account->user_id !== null) {
                app(DashboardCache::class)->forgetForUser((int) $account->user_id);
            }
        });

        static::deleted(function (TrackedAccount $account): void {
            if ($account->user_id !== null) {
                app(DashboardCache::class)->forgetForUser((int) $account->user_id);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'kind' => TrackedAccountKind::class,
            'is_own_account' => 'boolean',
            'followers' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<TrackedAccount>  $query
     * @return Builder<TrackedAccount>
     */
    public function scopeCompetitors(Builder $query): Builder
    {
        return $query->where('kind', TrackedAccountKind::Competitor);
    }

    /**
     * @param  Builder<TrackedAccount>  $query
     * @return Builder<TrackedAccount>
     */
    public function scopeInfluencers(Builder $query): Builder
    {
        return $query->where('kind', TrackedAccountKind::Influencer);
    }

    public function isSyncing(): bool
    {
        if ($this->last_sync_status !== 'running') {
            return false;
        }

        // Workers can die mid-job (deploy, OOM, timeout). A stuck "running"
        // status must not block weekly sync forever.
        return ! $this->hasStaleRunningSync();
    }

    public function hasStaleRunningSync(): bool
    {
        if ($this->last_sync_status !== 'running') {
            return false;
        }

        $staleAfterMinutes = max(30, (int) config('snitch.sync.stale_running_minutes', 180));
        $updatedAt = $this->updated_at;

        if ($updatedAt === null) {
            return true;
        }

        return $updatedAt->lte(now()->subMinutes($staleAfterMinutes));
    }

    public function markSyncRunning(): void
    {
        $this->fill([
            'last_sync_status' => 'running',
            'last_sync_error' => null,
        ])->save();
    }

    /**
     * Whether this account should be synced for new posts.
     *
     * Never-synced and failed syncs are always due. Fresh in-flight syncs are not.
     * Stale "running" rows (worker died) are treated as due again.
     * Successful syncs wait snitch.sync.min_interval_days (default 7) before
     * another Apify pull.
     */
    public function isDueForSync(?int $minIntervalDays = null): bool
    {
        if ($this->isSyncing()) {
            return false;
        }

        if ($this->hasStaleRunningSync()) {
            return true;
        }

        if ($this->last_synced_at === null) {
            return true;
        }

        if ($this->last_sync_status === 'failed') {
            return true;
        }

        $days = max(1, $minIntervalDays ?? (int) config('snitch.sync.min_interval_days', 7));

        return $this->last_synced_at->lte(now()->subDays($days));
    }

    /**
     * Earliest time a successful sync becomes eligible again.
     * Null when the account is already due (never synced, failed, or interval elapsed).
     */
    public function nextSyncAt(?int $minIntervalDays = null): ?CarbonInterface
    {
        if ($this->isDueForSync($minIntervalDays)) {
            return null;
        }

        $days = max(1, $minIntervalDays ?? (int) config('snitch.sync.min_interval_days', 7));

        return $this->last_synced_at?->copy()->addDays($days);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Global corpus posts for this tracked membership's social account.
     *
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'social_account_id', 'social_account_id');
    }
}
