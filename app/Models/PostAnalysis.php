<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use Database\Factories\PostAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'post_id',
    'status',
    'hook',
    'hook_window_end_sec',
    'visual_summary',
    'idea',
    'format_notes',
    'sfx',
    'music',
    'cta',
    'model',
    'error_message',
    'analyzed_at',
])]
class PostAnalysis extends Model
{
    /** @use HasFactory<PostAnalysisFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'hook_window_end_sec' => 'integer',
            'sfx' => 'array',
            'music' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
