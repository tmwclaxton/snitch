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
    ];

    protected function casts(): array
    {
        return [
            'vendor' => BillingVendor::class,
            'cogs_usd' => 'float',
            'multiplier' => 'float',
            'amount_pence' => 'integer',
            'balance_after_pence' => 'integer',
            'meta' => 'array',
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
