<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use Database\Factories\PostAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
    'how_to_copy',
    'transcript',
    'concept',
    'topics',
    'custom_tags',
    'embedding',
    'embedding_model',
    'embedding_hash',
    'model',
    'error_message',
    'analyzed_at',
])]
#[Hidden(['embedding', 'embedding_model', 'embedding_hash'])]
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
            'topics' => 'array',
            'custom_tags' => 'array',
            'embedding' => 'array',
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

    /**
     * @return BelongsToMany<AnalysisTerm, $this>
     */
    public function terms(): BelongsToMany
    {
        return $this->belongsToMany(AnalysisTerm::class)
            ->withTimestamps();
    }
}
