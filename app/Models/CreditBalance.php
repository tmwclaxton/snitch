<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditBalance extends Model
{
    protected $fillable = [
        'user_id',
        'balance_pence',
    ];

    protected function casts(): array
    {
        return [
            'balance_pence' => 'float',
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
