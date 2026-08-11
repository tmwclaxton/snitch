<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpToolInvocation extends Model
{
    protected $fillable = [
        'user_id',
        'tool',
        'ok',
        'error_code',
        'duration_ms',
        'auth',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'duration_ms' => 'integer',
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
