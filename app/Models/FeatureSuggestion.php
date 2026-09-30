<?php

namespace App\Models;

use Database\Factories\FeatureSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'title',
    'body',
    'status',
])]
class FeatureSuggestion extends Model
{
    /** @use HasFactory<FeatureSuggestionFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUSES = ['open', 'planned', 'building', 'shipped'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
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
     * @return HasMany<FeatureVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(FeatureVote::class, 'suggestion_id');
    }
}
