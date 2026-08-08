<?php

namespace App\Models;

use App\Enums\AnalysisTermDimension;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'dimension',
    'slug',
    'label',
])]
class AnalysisTerm extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimension' => AnalysisTermDimension::class,
        ];
    }

    /**
     * @return BelongsToMany<PostAnalysis, $this>
     */
    public function analyses(): BelongsToMany
    {
        return $this->belongsToMany(PostAnalysis::class)
            ->withTimestamps();
    }
}
