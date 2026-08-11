<?php

namespace App\Models;

use App\Enums\BillingVendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLedgerEntry extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'vendor',
        'cogs_usd',
        'multiplier',
        'amount_pence',
        'balance_after_pence',
        'meta',
        'idempotency_key',
        'expires_at',
        'remaining_pence',
    ];

    protected function casts(): array
    {
        return [
            'vendor' => BillingVendor::class,
            'cogs_usd' => 'float',
            'multiplier' => 'float',
            'amount_pence' => 'float',
            'balance_after_pence' => 'float',
            'remaining_pence' => 'float',
            'meta' => 'array',
            'expires_at' => 'datetime',
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
