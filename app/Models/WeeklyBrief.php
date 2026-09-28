<?php

namespace App\Models;

use Database\Factories\WeeklyBriefFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'week_start',
    'status',
    'best_times',
    'heat_grid',
    'thin_data',
    'credits_charged_pence',
    'was_free',
    'generated_at',
])]
class WeeklyBrief extends Model
{
    /** @use HasFactory<WeeklyBriefFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'best_times' => 'array',
            'heat_grid' => 'array',
            'thin_data' => 'boolean',
            'credits_charged_pence' => 'float',
            'was_free' => 'boolean',
            'generated_at' => 'datetime',
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
     * @return HasMany<WeeklyBriefIdea, $this>
     */
    public function ideas(): HasMany
    {
        return $this->hasMany(WeeklyBriefIdea::class)->orderBy('position');
    }
}
