<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AutomationRun extends Model
{
    public const TYPE_BRIEF = 'brief';

    public const TYPE_CHAT = 'chat';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMED_OUT = 'timed_out';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'uuid',
        'user_id',
        'type',
        'status',
        'cursor_run_id',
        'cursor_background_composer_id',
        'personal_access_token_id',
        'abilities',
        'request_meta',
        'result_meta',
        'cost_notes',
        'error',
        'user_message',
        'conversation',
        'reply_partial',
        'reply_final',
        'started_at',
        'finished_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'request_meta' => 'array',
            'result_meta' => 'array',
            'conversation' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AutomationRun $run): void {
            if ($run->uuid === null || $run->uuid === '') {
                $run->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AutomationCallAudit, $this>
     */
    public function callAudits(): HasMany
    {
        return $this->hasMany(AutomationCallAudit::class);
    }

    public function personalAccessToken(): ?PersonalAccessToken
    {
        if ($this->personal_access_token_id === null) {
            return null;
        }

        return PersonalAccessToken::query()->find($this->personal_access_token_id);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }
}
