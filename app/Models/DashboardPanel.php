<?php

namespace App\Models;

use Database\Factories\DashboardPanelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'position',
    'type',
    'title',
    'payload',
    'source',
])]
class DashboardPanel extends Model
{
    /** @use HasFactory<DashboardPanelFactory> */
    use HasFactory, SoftDeletes;

    public const TYPE_BIG_NUMBER = 'big_number';

    public const TYPE_POST_CARD = 'post_card';

    public const TYPE_RANKED_LIST = 'ranked_list';

    public const TYPE_LINE_CHART = 'line_chart';

    public const TYPE_BAR_CHART = 'bar_chart';

    public const TYPE_NOTE = 'note';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_BIG_NUMBER,
            self::TYPE_POST_CARD,
            self::TYPE_RANKED_LIST,
            self::TYPE_LINE_CHART,
            self::TYPE_BAR_CHART,
            self::TYPE_NOTE,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'position' => 'integer',
            'payload' => 'array',
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
