<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralVisit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'referral_code_id',
        'ip_hash',
        'user_agent',
        'visit_date',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReferralCode, $this>
     */
    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }
}
