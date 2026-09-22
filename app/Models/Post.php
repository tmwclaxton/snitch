<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use App\Enums\MediaAvailability;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Support\PostCover;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'social_account_id',
    'platform',
    'type',
    'external_id',
    'url',
    'posted_at',
    'caption',
    'media_url',
    'cover_url',
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
     * @var list<string>
     */
    protected $appends = [
        'cover_url',
    ];

    /**
     * Still image for grids and polaroids. Prefer payload covers over video files.
     *
     * @return Attribute<string|null, never>
     */
    protected function coverUrl(): Attribute
    {
        return Attribute::get(function (?string $value): ?string {
            if (PostCover::isDisplayableStill($value)) {
                return trim((string) $value);
            }

            return PostCover::resolve($this);
        });
    }

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
     * Posts for social accounts the user currently tracks.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereIn(
            'social_account_id',
            TrackedAccount::query()
                ->where('user_id', $user->id)
                ->select('social_account_id'),
        );
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
     * Reels plus carousels, images, and text posts that analysis can read.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeAnalysisCandidates(Builder $query): Builder
    {
        return $query->whereIn('type', [
            ...PostType::analyzableValues(),
            ...PostType::stillValues(),
        ]);
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
     * Posts still inside the sync/analyze recency window (or undated).
     *
     * YouTube list payloads often omit published_time; hydrate may later fill a
     * historical date. Those rows must not sit in the analysis backlog forever.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeWithinAnalysisRecency(Builder $query): Builder
    {
        $recencyDays = max(1, (int) config('snitch.sync.recency_days', 30));
        $cutoff = now()->subDays($recencyDays);

        return $query->where(function (Builder $query) use ($cutoff): void {
            $query->whereNull('posted_at')
                ->orWhere('posted_at', '>=', $cutoff);
        });
    }

    /**
     * Posts queued for analysis (synced, not yet completed).
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeAnalysisQueue(Builder $query): Builder
    {
        return $query
            ->mediaAvailable()
            ->withinAnalysisRecency()
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
     * Proof sheet: omit analyses we could not process. Those stay on /backlog.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeVisibleOnFeed(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('media_availability')
                    ->orWhere('media_availability', '!=', MediaAvailability::Unavailable);
            })
            ->whereDoesntHave('analysis', function (Builder $analysis): void {
                $analysis->whereIn('status', [
                    AnalysisStatus::Failed,
                    AnalysisStatus::Unavailable,
                ]);
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
            ->withinAnalysisRecency()
            ->whereHas('analysis', function (Builder $analysis): void {
                $analysis->where('status', AnalysisStatus::Failed);
            });
    }

    /**
     * Posts still waiting on a completed analysis (queue + failed).
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeAnalysisBacklog(Builder $query): Builder
    {
        return $query
            ->mediaAvailable()
            ->withinAnalysisRecency()
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
        if (! $this->type instanceof PostType || $this->media_availability === MediaAvailability::Unavailable) {
            return false;
        }

        if ($this->type->isReelLike()) {
            return filled($this->media_url);
        }

        if (! $this->type->isStill()) {
            return false;
        }

        return filled($this->media_url) || filled($this->caption) || filled($this->getRawOriginal('cover_url'));
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
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * @return HasOne<PostAnalysis, $this>
     */
    public function analysis(): HasOne
    {
        return $this->hasOne(PostAnalysis::class);
    }

    /**
     * User-scoped winner row. Always constrain with where('user_id', ...) when eager loading.
     *
     * @return HasOne<WinnerInsight, $this>
     */
    public function winnerInsight(): HasOne
    {
        return $this->hasOne(WinnerInsight::class);
    }
}
