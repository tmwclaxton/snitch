<?php

namespace App\Models;

use App\Enums\Platform;
use Database\Factories\SnitchDailyPlatformStatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'date',
    'platform',
    'posts_count',
])]
class SnitchDailyPlatformStat extends Model
{
    /** @use HasFactory<SnitchDailyPlatformStatFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'platform' => Platform::class,
            'posts_count' => 'integer',
        ];
    }
}
