<?php

namespace App\Models;

use Database\Factories\WinnerRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'preset',
    'min_engagement_rate',
    'min_views',
    'min_likes',
    'recency_days',
    'weights',
    'advanced',
])]
class WinnerRule extends Model
{
    /** @use HasFactory<WinnerRuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_engagement_rate' => 'integer',
            'min_views' => 'integer',
            'min_likes' => 'integer',
            'recency_days' => 'integer',
            'weights' => 'array',
            'advanced' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
