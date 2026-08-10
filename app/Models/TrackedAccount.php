<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
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
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
