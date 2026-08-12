<?php

namespace App\Models;

use Database\Factories\ReferralCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferralCode extends Model
{
    /** @use HasFactory<ReferralCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'notes',
        'is_active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function publicUrl(): string
    {
        return url('/?ref='.$this->code);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<ReferralVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(ReferralVisit::class);
    }
}
