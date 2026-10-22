<?php

namespace App\Models\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum personal access tokens for Cursor/agents (Passport handles OAuth).
 */
trait HasSanctumMcpTokens
{
    protected ?PersonalAccessToken $currentSanctumToken = null;

    /**
     * @return MorphMany<PersonalAccessToken, $this>
     */
    public function sanctumTokens()
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }

    public function currentSanctumToken(): ?PersonalAccessToken
    {
        return $this->currentSanctumToken;
    }

    public function withSanctumToken(?PersonalAccessToken $token): static
    {
        $this->currentSanctumToken = $token;

        return $this;
    }

    /**
     * @param  string[]  $abilities
     */
    public function createSanctumToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $plainTextToken = sprintf(
            '%s%s%s',
            config('sanctum.token_prefix', ''),
            $tokenEntropy = Str::random(40),
            hash('crc32b', $tokenEntropy)
        );

        $token = $this->sanctumTokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }
}
