<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAppUrls;
use App\Mcp\Support\McpAuth;
use App\Models\TrackedAccount;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Growth\GrowthMetricsBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_growth')]
#[Description('Follower, posting, engagement, and winner-multiplier series for /growth. Period is 30d, 90d, or all. Optional tracked account ids narrow rivals; own accounts stay included.')]
class GetGrowthTool extends Tool
{
    public function handle(
        Request $request,
        GrowthMetricsBuilder $growth,
        PlanEntitlementService $entitlements,
    ): Response {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user)) {
            return $blocked;
        }

        $data = $request->validate([
            'period' => ['nullable', 'string', 'in:30d,90d,all'],
            'accounts' => ['nullable', 'array', 'max:50'],
            'accounts.*' => ['integer'],
        ]);

        $period = $data['period'] ?? '30d';
        $selected = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            is_array($data['accounts'] ?? null) ? $data['accounts'] : [],
        ), static fn (int $id): bool => $id > 0));

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

        return Response::json([
            'period' => $period,
            'metrics' => $growth->build($user, $period, $selected),
            'account_options' => $options,
            'selected_accounts' => $selected,
            'app_url' => McpAppUrls::growth($period),
            'next_step' => 'For the written month summary and share link, call get_monthly_report.',
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()
                ->enum(['30d', '90d', 'all'])
                ->description('Window for the growth charts. Defaults to 30d.')
                ->nullable(),
            'accounts' => $schema->array()
                ->description('Tracked account ids to include besides own accounts. Omit for every in-quota account.')
                ->nullable(),
        ];
    }
}
