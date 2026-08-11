<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditBalance extends Model
{
    protected $fillable = [
        'user_id',
        'balance_pence',
        'starter_allowance_exhausted',
    ];

    protected function casts(): array
    {
        return [
            'balance_pence' => 'float',
            'starter_allowance_exhausted' => 'boolean',
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
