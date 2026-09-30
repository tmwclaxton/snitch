<?php

namespace App\Models;

use Database\Factories\FeatureVoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'suggestion_id',
])]
class FeatureVote extends Model
{
    /** @use HasFactory<FeatureVoteFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'suggestion_id' => 'integer',
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
     * @return BelongsTo<FeatureSuggestion, $this>
     */
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(FeatureSuggestion::class, 'suggestion_id');
    }
}
