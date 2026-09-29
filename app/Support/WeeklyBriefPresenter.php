<?php

namespace App\Support;

use App\Mcp\Support\McpAppUrls;
use App\Models\Post;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Models\WeeklyBriefIdea;
use App\Services\Brief\WeeklyBriefGenerator;

class WeeklyBriefPresenter
{
    /**
     * @return list<array{
     *     id: int,
     *     week_start: string|null,
     *     generated_at: string|null,
     *     was_free: bool,
     *     credits_charged_pence: float
     * }>
     */
    public function history(User $user): array
    {
        return WeeklyBrief::query()
            ->where('user_id', $user->id)
            ->orderByDesc('week_start')
            ->limit(12)
            ->get(['id', 'week_start', 'generated_at', 'was_free', 'credits_charged_pence'])
            ->map(fn (WeeklyBrief $row): array => [
                'id' => $row->id,
                'week_start' => $row->week_start?->toDateString(),
                'generated_at' => $row->generated_at?->toIso8601String(),
                'was_free' => (bool) $row->was_free,
                'credits_charged_pence' => (float) $row->credits_charged_pence,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(WeeklyBrief $brief, User $user, WeeklyBriefGenerator $generator): array
    {
        $postIds = $brief->ideas
            ->flatMap(fn (WeeklyBriefIdea $idea) => $idea->inspired_by_post_ids ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $posts = $postIds === []
            ? collect()
            : Post::query()
                ->whereIn('id', $postIds)
                ->with(['socialAccount'])
                ->get()
                ->keyBy('id');

        $winnerLookup = collect($generator->topWinners($user, 30))
            ->keyBy('post_id');

        return [
            'id' => $brief->id,
            'week_start' => $brief->week_start?->toDateString(),
            'status' => $brief->status,
            'best_times' => $brief->best_times ?? [],
            'heat_grid' => $brief->heat_grid ?? [],
            'thin_data' => (bool) $brief->thin_data,
            'was_free' => (bool) $brief->was_free,
            'credits_charged_pence' => (float) $brief->credits_charged_pence,
            'generated_at' => $brief->generated_at?->toIso8601String(),
            'app_url' => McpAppUrls::brief($brief->week_start?->toDateString()),
            'ideas' => $brief->ideas->map(function (WeeklyBriefIdea $idea) use ($posts, $winnerLookup): array {
                $sources = collect($idea->inspired_by_post_ids ?? [])
                    ->map(function ($id) use ($posts, $winnerLookup): ?array {
                        $post = $posts->get((int) $id);

                        if ($post === null) {
                            return null;
                        }

                        $winner = $winnerLookup->get((int) $post->id);

                        return [
                            'id' => (int) $post->id,
                            'url' => $post->url,
                            'snitch_url' => McpAppUrls::feedPost($post),
                            'thumbnail_url' => $post->cover_url,
                            'handle' => (string) ($winner['handle'] ?? $post->socialAccount?->handle ?? 'unknown'),
                            'pi' => isset($winner['pi']) ? (float) $winner['pi'] : null,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'id' => $idea->id,
                    'position' => $idea->position,
                    'format' => $idea->format,
                    'hook' => $idea->hook,
                    'visual' => $idea->visual,
                    'caption_angle' => $idea->caption_angle,
                    'cta' => $idea->cta,
                    'hashtags' => $idea->hashtags ?? [],
                    'recommended_day' => $idea->recommended_day,
                    'recommended_hour' => $idea->recommended_hour,
                    'why' => $idea->why,
                    'used_at' => $idea->used_at?->toIso8601String(),
                    'inspired_by' => $sources,
                ];
            })->values()->all(),
        ];
    }
}
