<?php

namespace App\Support;

final class SyncOptions
{
    public function __construct(
        public readonly ?int $postsLimit = null,
        public readonly ?int $recencyDays = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(array $data): self
    {
        return new self(
            postsLimit: array_key_exists('posts_limit', $data) && $data['posts_limit'] !== null
                ? (int) $data['posts_limit']
                : null,
            recencyDays: array_key_exists('recency_days', $data) && $data['recency_days'] !== null
                ? (int) $data['recency_days']
                : null,
        );
    }

    public function resolvedPostsLimit(): int
    {
        $default = max(1, (int) config('snitch.sync.posts_limit', 12));
        $max = max($default, (int) config('snitch.sync.posts_limit_max', 50));

        if ($this->postsLimit === null) {
            return $default;
        }

        return min(max(1, $this->postsLimit), $max);
    }

    public function resolvedRecencyDays(): int
    {
        $default = max(1, (int) config('snitch.sync.recency_days', 30));
        $max = max($default, (int) config('snitch.sync.recency_days_max', 90));

        if ($this->recencyDays === null) {
            return $default;
        }

        return min(max(1, $this->recencyDays), $max);
    }

    /**
     * @return array{
     *     posts_limit: int,
     *     recency_days: int,
     *     posts_limit_max: int,
     *     recency_days_max: int
     * }
     */
    public static function inertiaDefaults(): array
    {
        return [
            'posts_limit' => max(1, (int) config('snitch.sync.posts_limit', 12)),
            'recency_days' => max(1, (int) config('snitch.sync.recency_days', 30)),
            'posts_limit_max' => max(
                (int) config('snitch.sync.posts_limit', 12),
                (int) config('snitch.sync.posts_limit_max', 50),
            ),
            'recency_days_max' => max(
                (int) config('snitch.sync.recency_days', 30),
                (int) config('snitch.sync.recency_days_max', 90),
            ),
        ];
    }

    /**
     * @return array<string, list<string|int>>
     */
    public static function optionalFieldRules(): array
    {
        $postsMax = max(
            (int) config('snitch.sync.posts_limit', 12),
            (int) config('snitch.sync.posts_limit_max', 50),
        );
        $recencyMax = max(
            (int) config('snitch.sync.recency_days', 30),
            (int) config('snitch.sync.recency_days_max', 90),
        );

        return [
            'posts_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.$postsMax],
            'recency_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.$recencyMax],
        ];
    }
}
