<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Services\SocialAccounts\SocialAccountResolver;
use Carbon\CarbonInterface;
use Database\Factories\TrackedAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'social_account_id',
    'platform',
    'kind',
    'handle',
    'url',
    'external_id',
    'avatar',
    'display_name',
    'fit_reason',
    'last_synced_at',
    'last_sync_status',
    'last_sync_error',
])]
class TrackedAccount extends Model
{
    /** @use HasFactory<TrackedAccountFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (TrackedAccount $account): void {
            if ($account->social_account_id !== null) {
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
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'kind' => TrackedAccountKind::class,
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
        return $this->last_sync_status === 'running';
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
     * Never-synced and failed syncs are always due. In-flight syncs are not.
     * Successful syncs wait snitch.sync.min_interval_days (default 7) before
     * another Apify pull.
     */
    public function isDueForSync(?int $minIntervalDays = null): bool
    {
        if ($this->isSyncing()) {
            return false;
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
