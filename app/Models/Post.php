<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\PostType;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
            'posted_at' => 'datetime',
            'metrics' => 'array',
            'raw_payload' => 'array',
        ];
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
