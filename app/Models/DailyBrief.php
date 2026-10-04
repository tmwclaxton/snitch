<?php

namespace App\Models;

use Database\Factories\DailyBriefFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'brief_date',
    'status',
    'headline',
    'payload',
    'facts',
    'model',
    'llm_attempts',
    'credits_charged_pence',
    'was_free',
    'generated_at',
])]
class DailyBrief extends Model
{
    /** @use HasFactory<DailyBriefFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'generating',
        'llm_attempts' => 0,
        'credits_charged_pence' => 0,
        'was_free' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'brief_date' => 'date',
            'payload' => 'array',
            'facts' => 'array',
            'llm_attempts' => 'integer',
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
}
