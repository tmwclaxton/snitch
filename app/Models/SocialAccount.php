<?php

namespace App\Models;

use App\Enums\Platform;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'platform',
    'handle',
    'url',
    'external_id',
    'avatar',
    'display_name',
])]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
        ];
    }

    /**
     * @return HasMany<TrackedAccount, $this>
     */
    public function trackedAccounts(): HasMany
    {
        return $this->hasMany(TrackedAccount::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
