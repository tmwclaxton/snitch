<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\McpAuth;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Cashier\Subscription;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_users')]
#[Description('Curator only. List users with id, name, email, plan, last login, and tracker count. Read-only; no billing or auth mutations.')]
#[IsReadOnly]
class ListUsersTool extends Tool
{
    public function handle(Request $request): Response
    {
        $actor = McpAuth::user($request);
        if ($actor instanceof Response) {
            return $actor;
        }

        if ($blocked = McpAuth::requireCurator($actor)) {
            return $blocked;
        }

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($data['limit'] ?? 50);
        $q = isset($data['q']) ? trim((string) $data['q']) : '';

        $users = User::query()
            ->withCount('trackedAccounts')
            ->when($q !== '', function ($query) use ($q): void {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like): void {
                    $inner->where('email', 'like', $like)
                        ->orWhere('name', 'like', $like);
                });
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $subscriptionType = (string) config('billing.subscription_type', 'default');

        $rows = $users->map(function (User $user) use ($subscriptionType): array {
            /** @var Subscription|null $sub */
            $sub = $user->subscription($subscriptionType);
            $plan = 'None';
            if ($sub !== null && $sub->valid()) {
                $plan = $sub->onTrial() ? 'Trialing' : 'Active';
            }

            return [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'plan' => $plan,
                'last_login_at' => $user->updated_at?->toIso8601String(),
                'tracker_count' => (int) $user->tracked_accounts_count,
            ];
        })->values()->all();

        return Response::json([
            'users' => $rows,
            'count' => count($rows),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Optional name or email search.')->nullable(),
            'limit' => $schema->integer()->description('Max users to return (1-100).')->nullable(),
        ];
    }
}
