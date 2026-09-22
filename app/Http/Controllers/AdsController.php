<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Models\SocialAd;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Competitors\CompetitorInsightsBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdsController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function __construct(private PlanEntitlementService $entitlements) {}

    public function index(Request $request, CompetitorInsightsBuilder $insights): Response
    {
        $user = $request->user();

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('ad-library/Index', [
                'ads' => [],
                'total' => 0,
            ]);
        }

        $socialIds = $this->socialIdsForUser($user);

        return Inertia::render('ad-library/Index', [
            'ads' => Inertia::defer(
                fn (): array => $insights->adsCatalogue($user, $socialIds),
                'ads',
            ),
            'total' => $socialIds === []
                ? 0
                : SocialAd::query()
                    ->whereIn('social_account_id', $socialIds)
                    ->where('is_active', true)
                    ->count(),
        ]);
    }

    /**
     * @return list<int>
     */
    private function socialIdsForUser(User $user): array
    {
        $inQuotaIds = $this->entitlements->inQuotaTrackedAccountIds($user);

        if ($inQuotaIds === []) {
            return [];
        }

        return TrackedAccount::query()
            ->whereIn('id', $inQuotaIds)
            ->pluck('social_account_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
