<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use App\Enums\MediaAvailability;
use App\Enums\Platform;
use App\Enums\PostType;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'tracked_account_id',
    'platform',
    'type',
    'external_id',
    'url',
    'posted_at',
    'caption',
    'media_url',
    'media_availability',
    'unavailable_at',
    'unavailable_reason',
    'metrics',
    'raw_payload',
])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'type' => PostType::class,
            'media_availability' => MediaAvailability::class,
            'posted_at' => 'datetime',
            'unavailable_at' => 'datetime',
            'metrics' => 'array',
            'raw_payload' => 'array',
        ];
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeReelLike(Builder $query): Builder
    {
        return $query->whereIn('type', PostType::analyzableValues());
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeMediaAvailable(Builder $query): Builder
    {
        return $query->where('media_availability', MediaAvailability::Available);
    }

    /**
     * Reels queued for analysis (synced, not yet completed).
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeAnalysisQueue(Builder $query): Builder
    {
        return $query
            ->mediaAvailable()
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('analysis')
                    ->orWhereHas('analysis', function (Builder $analysis): void {
                        $analysis->whereIn('status', [
                            AnalysisStatus::Pending,
                            AnalysisStatus::Processing,
                        ]);
                    });
            });
    }

    /**
     * Reels whose analysis failed and may need another pass.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeAnalysisFailed(Builder $query): Builder
    {
        return $query
            ->mediaAvailable()
            ->whereHas('analysis', function (Builder $analysis): void {
                $analysis->where('status', AnalysisStatus::Failed);
            });
    }

    /**
     * Reels still waiting on a completed analysis (queue + failed).
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeAnalysisBacklog(Builder $query): Builder
    {
        return $query
            ->mediaAvailable()
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('analysis')
                    ->orWhereHas('analysis', function (Builder $analysis): void {
                        $analysis->whereIn('status', [
                            AnalysisStatus::Pending,
                            AnalysisStatus::Processing,
                            AnalysisStatus::Failed,
                        ]);
                    });
            });
    }

    public function markUnavailable(string $reason): void
    {
        $this->fill([
            'media_availability' => MediaAvailability::Unavailable,
            'unavailable_at' => now(),
            'unavailable_reason' => $reason,
        ])->save();
    }

    public function markAvailable(): void
    {
        $this->fill([
            'media_availability' => MediaAvailability::Available,
            'unavailable_at' => null,
            'unavailable_reason' => null,
        ])->save();
    }

    public function isAnalyzable(): bool
    {
        return $this->type instanceof PostType
            && $this->type->isReelLike()
            && filled($this->media_url)
            && $this->media_availability !== MediaAvailability::Unavailable;
    }

    /**
     * YouTube Shorts sync often stores a page URL; NanoGPT needs a file URL.
     */
    public function youtubeMediaIsPageUrl(): bool
    {
        if ($this->platform !== Platform::Youtube) {
            return false;
        }

        $mediaUrl = strtolower((string) $this->media_url);

        if ($mediaUrl === '') {
            return true;
        }

        if (! str_contains($mediaUrl, 'youtube.com/') && ! str_contains($mediaUrl, 'youtu.be/')) {
            return false;
        }

        return preg_match('/\.(mp4|webm|m3u8)(\?|$)/i', $mediaUrl) !== 1;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<TrackedAccount, $this>
     */
    public function trackedAccount(): BelongsTo
    {
        return $this->belongsTo(TrackedAccount::class);
    }

    /**
     * @return HasOne<PostAnalysis, $this>
     */
    public function analysis(): HasOne
    {
        return $this->hasOne(PostAnalysis::class);
    }

    /**
     * @return HasOne<WinnerInsight, $this>
     */
    public function winnerInsight(): HasOne
    {
        return $this->hasOne(WinnerInsight::class);
    }
}
