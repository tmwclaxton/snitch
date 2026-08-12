<?php

namespace App\Mcp\Tools;

use App\Enums\AnalysisTermDimension;
use App\Mcp\Support\McpAppUrls;
use App\Mcp\Support\McpAuth;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WinnerInsight;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_winners')]
#[Description('List winner insights for the authenticated user. Optional q / topics / platform filters soft-rank or narrow results. Each post includes snitch_url (app deep link) plus how_to_copy when analysed.')]
class ListWinnersTool extends Tool
{
    public function handle(Request $request, PlanEntitlementService $entitlements): Response
    {
        $user = McpAuth::user($request);
        if ($user instanceof Response) {
            return $user;
        }

        if ($blocked = McpAuth::requireProductAccess($user)) {
            return $blocked;
        }

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'platform' => ['nullable', 'string', 'in:instagram,tiktok,youtube,facebook,linkedin'],
            'topics' => ['nullable', 'array', 'max:20'],
            'topics.*' => ['string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = (int) ($data['limit'] ?? 30);
        $queryText = isset($data['q']) ? trim((string) $data['q']) : '';
        $topics = array_values(array_filter(array_map(
            static fn (mixed $topic): string => trim((string) $topic),
            is_array($data['topics'] ?? null) ? $data['topics'] : [],
        ), static fn (string $topic): bool => $topic !== ''));

        $socialIds = $this->inQuotaSocialAccountIds($user, $entitlements);

        $query = WinnerInsight::query()
            ->where('user_id', $user->id)
            ->whereHas('post', function (Builder $postQuery) use ($socialIds, $data): void {
                if ($socialIds === []) {
                    $postQuery->whereRaw('0 = 1');

                    return;
                }

                $postQuery->whereIn('social_account_id', $socialIds);

                if (! empty($data['platform'])) {
                    $postQuery->where('platform', $data['platform']);
                }
            })
            ->with([
                'post:id,url,platform,caption,posted_at,social_account_id,type',
                'post.analysis:id,post_id,status,hook,how_to_copy,concept,idea,topics',
                'post.analysis.terms' => fn ($terms) => $terms
                    ->where('dimension', AnalysisTermDimension::Topic->value)
                    ->select(['analysis_terms.id', 'dimension', 'slug', 'label']),
            ])
            ->orderByDesc('score')
            ->limit(max($limit * 3, 60));

        if ($topics !== []) {
            $query->whereHas('post.analysis.terms', function (Builder $terms) use ($topics): void {
                $terms
                    ->where('dimension', AnalysisTermDimension::Topic->value)
                    ->whereIn('slug', $topics);
            });
        }

        /** @var Collection<int, WinnerInsight> $winners */
        $winners = $query->get();

        if ($queryText !== '') {
            $needle = mb_strtolower($queryText);
            $ranked = $winners
                ->map(function (WinnerInsight $winner) use ($needle): array {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $winner->why,
                        $winner->how_to_copy,
                        $winner->post?->caption,
                        $winner->post?->analysis?->hook,
                        $winner->post?->analysis?->concept,
                        $winner->post?->analysis?->idea,
                        is_array($winner->post?->analysis?->topics)
                            ? implode(' ', $winner->post->analysis->topics)
                            : null,
                    ])));

                    $scoreBoost = str_contains($haystack, $needle) ? 1000.0 : 0.0;

                    return ['winner' => $winner, 'rank' => $scoreBoost + (float) $winner->score];
                })
                ->sortByDesc('rank')
                ->values();

            $matched = $ranked->filter(fn (array $row): bool => $row['rank'] >= 1000.0);
            $winners = ($matched->isNotEmpty() ? $matched : $ranked)
                ->take($limit)
                ->map(fn (array $row): WinnerInsight => $row['winner'])
                ->values();
        } else {
            $winners = $winners->take($limit)->values();
        }

        $posts = $winners->pluck('post')->filter();
        McpAppUrls::attachSnitchUrls($posts);

        $payload = $winners->map(function (WinnerInsight $winner): array {
            $post = $winner->post;
            $analysis = $post?->analysis;

            return [
                'id' => $winner->id,
                'user_id' => $winner->user_id,
                'post_id' => $winner->post_id,
                'score' => $winner->score,
                'why' => $winner->why,
                'how_to_copy' => $winner->how_to_copy ?? $analysis?->how_to_copy,
                'created_at' => $winner->created_at,
                'updated_at' => $winner->updated_at,
                'post' => $post === null ? null : [
                    'id' => $post->id,
                    'url' => $post->url,
                    'snitch_url' => $post->getAttribute('snitch_url') ?? McpAppUrls::feedPost($post),
                    'platform' => $post->platform,
                    'caption' => $post->caption,
                    'posted_at' => $post->posted_at,
                    'analysis' => $analysis === null ? null : [
                        'hook' => $analysis->hook,
                        'how_to_copy' => $analysis->how_to_copy,
                        'concept' => $analysis->concept,
                        'topics' => $analysis->topics,
                        'term_slugs' => $analysis->relationLoaded('terms')
                            ? $analysis->terms->pluck('slug')->values()->all()
                            : [],
                    ],
                ],
            ];
        })->values()->all();

        return Response::json([
            'winners' => $payload,
            'winners_url' => McpAppUrls::winners(),
            'filters' => [
                'q' => $queryText !== '' ? $queryText : null,
                'platform' => $data['platform'] ?? null,
                'topics' => $topics,
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * @return list<int>
     */
    private function inQuotaSocialAccountIds(User $user, PlanEntitlementService $entitlements): array
    {
        $inQuotaIds = $entitlements->inQuotaTrackedAccountIds($user);

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

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->nullable(),
            'platform' => $schema->string()->nullable(),
            'topics' => $schema->array()->items($schema->string())->nullable(),
            'limit' => $schema->integer()->nullable(),
        ];
    }
}
