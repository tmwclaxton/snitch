<?php

namespace App\Models;

use App\Enums\Platform;
use Database\Factories\SocialAdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'social_account_id',
    'platform',
    'title',
    'body',
    'url',
    'thumbnail_url',
    'is_active',
    'last_seen_at',
    'raw',
])]
class SocialAd extends Model
{
    /** @use HasFactory<SocialAdFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
