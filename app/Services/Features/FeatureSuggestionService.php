<?php

namespace App\Services\Features;

use App\Models\FeatureSuggestion;
use App\Models\FeatureVote;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeatureSuggestionService
{
    /**
     * @return list<array{
     *     id: int,
     *     title: string,
     *     body: string,
     *     status: string,
     *     votes_count: int,
     *     voted: bool
     * }>
     */
    public function forDashboard(User $user): array
    {
        $votedIds = FeatureVote::query()
            ->where('user_id', $user->id)
            ->pluck('suggestion_id')
            ->all();

        return FeatureSuggestion::query()
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderBy('id')
            ->get()
            ->map(fn (FeatureSuggestion $suggestion): array => [
                'id' => $suggestion->id,
                'title' => $suggestion->title,
                'body' => $suggestion->body,
                'status' => $suggestion->status,
                'votes_count' => (int) $suggestion->votes_count,
                'voted' => in_array($suggestion->id, $votedIds, true),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{title: string, body: string}  $data
     * @return array{
     *     id: int,
     *     title: string,
     *     body: string,
     *     status: string,
     *     votes_count: int,
     *     voted: bool
     * }
     */
    public function submit(User $user, array $data): array
    {
        $suggestion = FeatureSuggestion::query()->create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => 'open',
        ]);

        FeatureVote::query()->create([
            'user_id' => $user->id,
            'suggestion_id' => $suggestion->id,
        ]);

        return [
            'id' => $suggestion->id,
            'title' => $suggestion->title,
            'body' => $suggestion->body,
            'status' => $suggestion->status,
            'votes_count' => 1,
            'voted' => true,
        ];
    }

    /**
     * @return array{voted: bool, votes_count: int}
     */
    public function toggleVote(User $user, FeatureSuggestion $suggestion): array
    {
        return DB::transaction(function () use ($user, $suggestion): array {
            $existing = FeatureVote::query()
                ->where('user_id', $user->id)
                ->where('suggestion_id', $suggestion->id)
                ->first();

            if ($existing !== null) {
                $existing->delete();
            } else {
                FeatureVote::query()->create([
                    'user_id' => $user->id,
                    'suggestion_id' => $suggestion->id,
                ]);
            }

            return [
                'voted' => $existing === null,
                'votes_count' => $suggestion->votes()->count(),
            ];
        });
    }

    /**
     * @return Collection<int, array{
     *     id: int,
     *     title: string,
     *     body: string,
     *     status: string,
     *     votes_count: int,
     *     author: string|null,
     *     created_at: string|null
     * }>
     */
    public function forAdmin(): Collection
    {
        return FeatureSuggestion::query()
            ->with(['user:id,name,email'])
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderBy('id')
            ->get()
            ->map(fn (FeatureSuggestion $suggestion): array => [
                'id' => $suggestion->id,
                'title' => $suggestion->title,
                'body' => $suggestion->body,
                'status' => $suggestion->status,
                'votes_count' => (int) $suggestion->votes_count,
                'author' => $suggestion->user?->email,
                'created_at' => $suggestion->created_at?->toIso8601String(),
            ]);
    }

    public function updateStatus(FeatureSuggestion $suggestion, string $status): FeatureSuggestion
    {
        if (! in_array($status, FeatureSuggestion::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Choose a valid status.',
            ]);
        }

        $suggestion->forceFill(['status' => $status])->save();

        return $suggestion->refresh();
    }
}
