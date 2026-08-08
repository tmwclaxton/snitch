<?php

namespace App\Models;

use Database\Factories\SnitchDailyStatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'date',
    'posts_count',
    'analyses_count',
    'winners_count',
])]
class SnitchDailyStat extends Model
{
    /** @use HasFactory<SnitchDailyStatFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'posts_count' => 'integer',
            'analyses_count' => 'integer',
            'winners_count' => 'integer',
        ];
    }
}
