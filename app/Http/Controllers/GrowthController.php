<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OmitsProductDataWhenPaywalled;
use App\Models\TrackedAccount;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Growth\GrowthMetricsBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GrowthController extends Controller
{
    use OmitsProductDataWhenPaywalled;

    public function index(
        Request $request,
        GrowthMetricsBuilder $growth,
        PlanEntitlementService $entitlements,
    ): Response {
        $user = $request->user();
        $period = $request->string('period')->toString();

        if (! in_array($period, ['30d', '90d', 'all'], true)) {
            $period = '30d';
        }

        $selected = collect($request->query('accounts', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        if ($this->productAccessBlocked($user)) {
            return Inertia::render('growth/Index', [
                'period' => $period,
                'metrics' => null,
                'accountOptions' => [],
                'selectedAccounts' => [],
            ]);
        }

        $ids = $entitlements->inQuotaTrackedAccountIds($user);
        $options = $ids === []
            ? []
            : TrackedAccount::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $ids)
                ->orderByDesc('is_own_account')
                ->orderBy('handle')
                ->get(['id', 'handle', 'is_own_account', 'platform'])
                ->map(fn (TrackedAccount $account): array => [
                    'id' => (int) $account->id,
                    'handle' => (string) $account->handle,
                    'is_own_account' => (bool) $account->is_own_account,
                    'platform' => $account->platform instanceof \BackedEnum
                        ? $account->platform->value
                        : (string) $account->platform,
                ])
                ->all();

        return Inertia::render('growth/Index', [
            'period' => $period,
            'metrics' => $growth->build($user, $period, $selected),
            'accountOptions' => $options,
            'selectedAccounts' => $selected,
        ]);
    }
}
