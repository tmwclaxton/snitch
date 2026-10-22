<?php

namespace App\Services\Automations;

use App\Models\AutomationRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

class AutomationRunTokenIssuer
{
    public const TOKEN_NAME_PREFIX = 'automation:';

    public const ABILITY_BRIEF_READ = 'automation:brief:read';

    public const ABILITY_BRIEF_WRITE = 'automation:brief:write';

    public const ABILITY_CHAT_READ = 'automation:chat:read';

    public const ABILITY_CHAT_WRITE = 'automation:chat:write';

    /**
     * @return array{run: AutomationRun, plain_text_token: string}
     */
    public function issue(User $user, string $type, array $requestMeta = []): array
    {
        $type = $type === AutomationRun::TYPE_CHAT
            ? AutomationRun::TYPE_CHAT
            : AutomationRun::TYPE_BRIEF;

        $ttlMinutes = max(5, (int) config('services.cursor_automations.token_ttl_minutes', 30));
        $expiresAt = CarbonImmutable::now('UTC')->addMinutes($ttlMinutes);
        $abilities = $this->abilitiesFor($type);

        $run = AutomationRun::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'status' => AutomationRun::STATUS_QUEUED,
            'abilities' => $abilities,
            'request_meta' => $requestMeta,
            'expires_at' => $expiresAt,
        ]);

        $tokenName = self::TOKEN_NAME_PREFIX.$type.':'.$run->uuid;
        $accessToken = $user->createSanctumToken($tokenName, $abilities, $expiresAt);

        $run->forceFill([
            'personal_access_token_id' => $accessToken->accessToken->id,
            'started_at' => now(),
            'status' => AutomationRun::STATUS_RUNNING,
        ])->save();

        return [
            'run' => $run->fresh(),
            'plain_text_token' => $accessToken->plainTextToken,
        ];
    }

    public function revoke(AutomationRun $run, ?string $status = null, ?string $error = null): void
    {
        if ($run->personal_access_token_id !== null) {
            PersonalAccessToken::query()
                ->where('id', $run->personal_access_token_id)
                ->delete();
        }

        $run->forceFill([
            'personal_access_token_id' => null,
            'status' => $status ?? ($run->isTerminal() ? $run->status : AutomationRun::STATUS_REVOKED),
            'error' => $error ?? $run->error,
            'finished_at' => $run->finished_at ?? now(),
        ])->save();
    }

    public function complete(AutomationRun $run, string $status = AutomationRun::STATUS_SUCCEEDED, ?array $resultMeta = null, ?string $error = null): void
    {
        if ($run->personal_access_token_id !== null) {
            PersonalAccessToken::query()
                ->where('id', $run->personal_access_token_id)
                ->delete();
        }

        $meta = is_array($run->result_meta) ? $run->result_meta : [];
        if (is_array($resultMeta)) {
            $meta = array_merge($meta, $resultMeta);
        }

        $run->forceFill([
            'personal_access_token_id' => null,
            'status' => $status,
            'result_meta' => $meta,
            'error' => $error,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @return list<string>
     */
    public function abilitiesFor(string $type): array
    {
        return $type === AutomationRun::TYPE_CHAT
            ? [self::ABILITY_CHAT_READ, self::ABILITY_CHAT_WRITE]
            : [self::ABILITY_BRIEF_READ, self::ABILITY_BRIEF_WRITE];
    }

    public function findRunForToken(?PersonalAccessToken $token): ?AutomationRun
    {
        if ($token === null) {
            return null;
        }

        $name = (string) $token->name;
        if (! str_starts_with($name, self::TOKEN_NAME_PREFIX)) {
            return null;
        }

        return AutomationRun::query()
            ->where('personal_access_token_id', $token->id)
            ->first();
    }
}
