<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\ScopeAuthorizable;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenFactory;
use Laravel\Passport\PersonalAccessTokenResult;
use Laravel\Passport\Token;
use LogicException;

trait HasPassportOAuth
{
    protected ?ScopeAuthorizable $passportAccessToken = null;

    /**
     * @return MorphMany<Client, $this>
     */
    public function oauthApps(): MorphMany
    {
        return $this->morphMany(Passport::clientModel(), 'owner');
    }

    /**
     * @return HasMany<Token, $this>
     */
    public function tokens(): HasMany
    {
        return $this->passportTokens();
    }

    /**
     * @return HasMany<Token, $this>
     */
    public function passportTokens(): HasMany
    {
        return $this->hasMany(Passport::tokenModel(), 'user_id', $this->getAuthIdentifierName())
            ->where(function (Builder $query): void {
                $query->whereHas('client', function (Builder $query): void {
                    $query->where(function (Builder $query): void {
                        $provider = $this->getProviderName();

                        $query->when($provider === config('auth.guards.api.provider'), function (Builder $query): void {
                            $query->orWhereNull('provider');
                        })->orWhere('provider', $provider);
                    });
                });
            });
    }

    public function currentAccessToken(): ?ScopeAuthorizable
    {
        return $this->passportAccessToken;
    }

    public function tokenCan(string $scope): bool
    {
        return $this->passportAccessToken !== null && $this->passportAccessToken->can($scope);
    }

    public function tokenCant(string $scope): bool
    {
        return ! $this->tokenCan($scope);
    }

    /**
     * @param  string[]  $scopes
     */
    public function createToken(string $name, array $scopes = []): PersonalAccessTokenResult
    {
        return app(PersonalAccessTokenFactory::class)->make(
            $this->getAuthIdentifier(), $name, $scopes, $this->getProviderName()
        );
    }

    public function getProviderName(): string
    {
        $providers = collect(config('auth.guards'))->where('driver', 'passport')->pluck('provider')->all();

        foreach (config('auth.providers') as $provider => $config) {
            if (in_array($provider, $providers) && $config['driver'] === 'eloquent' && is_a($this, $config['model'])) {
                return $provider;
            }
        }

        throw new LogicException('Unable to determine authentication provider for this model from configuration.');
    }

    public function withAccessToken(?ScopeAuthorizable $accessToken): static
    {
        $this->passportAccessToken = $accessToken;

        return $this;
    }
}
