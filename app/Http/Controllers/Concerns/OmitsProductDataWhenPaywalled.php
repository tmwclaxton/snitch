<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Billing\UsageBillingService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * When the user is paywalled, product pages must render empty stubs only.
 * Security is server-side omission of data, not CSS blur.
 */
trait OmitsProductDataWhenPaywalled
{
    protected function productAccessBlocked(?User $user): bool
    {
        if ($user === null) {
            return true;
        }

        return ! app(UsageBillingService::class)->canAccessProduct($user);
    }

    /**
     * @return LengthAwarePaginator<int, mixed>
     */
    protected function emptyProductPaginator(int $perPage = 24): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, $perPage, 1, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }
}
