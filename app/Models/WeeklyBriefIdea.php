<?php

namespace App\Models;

use Database\Factories\WeeklyBriefIdeaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'weekly_brief_id',
    'position',
    'format',
    'hook',
    'visual',
    'caption_angle',
    'cta',
    'hashtags',
    'recommended_day',
    'recommended_hour',
    'inspired_by_post_ids',
    'why',
    'used_at',
])]
class WeeklyBriefIdea extends Model
{
    /** @use HasFactory<WeeklyBriefIdeaFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'hashtags' => 'array',
            'recommended_hour' => 'integer',
            'inspired_by_post_ids' => 'array',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WeeklyBrief, $this>
     */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(WeeklyBrief::class, 'weekly_brief_id');
    }
}
