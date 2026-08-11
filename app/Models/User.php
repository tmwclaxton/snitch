<?php

namespace App\Models;

use App\Models\Concerns\HasPassportOAuth;
use App\Models\Concerns\HasSanctumMcpTokens;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Stripe\Customer;
use Stripe\Exception\InvalidRequestException;

#[Fillable(['name', 'email', 'workos_id', 'avatar', 'created_via', 'claim_token', 'claimed_at'])]
#[Hidden(['workos_id', 'remember_token', 'claim_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, HasPassportOAuth, HasSanctumMcpTokens, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'trial_ends_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function isClaimed(): bool
    {
        return $this->claimed_at !== null && filled($this->workos_id);
    }

    /**
     * Recreate the Stripe customer when the stored id is missing in the
     * current Stripe mode/account (e.g. sandbox id after switching to live).
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $requestOptions
     * @return Customer
     */
    public function createOrGetStripeCustomer(array $options = [], array $requestOptions = [])
    {
        if ($this->hasStripeId()) {
            try {
                return $this->asStripeCustomer($options['expand'] ?? []);
            } catch (InvalidRequestException $exception) {
                if (! str_contains($exception->getMessage(), 'No such customer')) {
                    throw $exception;
                }

                $this->forceFill(['stripe_id' => null])->save();
            }
        }

        return $this->createAsStripeCustomer($options, $requestOptions);
    }

    /**
     * @return HasOne<BrandProfile, $this>
     */
    public function brandProfile(): HasOne
    {
        return $this->hasOne(BrandProfile::class);
    }

    /**
     * @return HasMany<TrackedAccount, $this>
     */
    public function trackedAccounts(): HasMany
    {
        return $this->hasMany(TrackedAccount::class);
    }

    /**
     * @return HasOne<WinnerRule, $this>
     */
    public function winnerRule(): HasOne
    {
        return $this->hasOne(WinnerRule::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return HasMany<WinnerInsight, $this>
     */
    public function winnerInsights(): HasMany
    {
        return $this->hasMany(WinnerInsight::class);
    }

    /**
     * @return HasOne<CreditBalance, $this>
     */
    public function creditBalance(): HasOne
    {
        return $this->hasOne(CreditBalance::class);
    }

    /**
     * @return HasMany<CreditLedgerEntry, $this>
     */
    public function creditLedgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }
}
