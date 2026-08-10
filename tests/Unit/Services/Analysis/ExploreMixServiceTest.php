<?php

namespace Tests\Unit\Services\Analysis;

use App\Models\Post;
use App\Models\WinnerInsight;
use App\Services\Analysis\ExploreMixService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExploreMixServiceTest extends TestCase
{
    #[Test]
    public function quality_score_prefers_winner_insight_over_metrics(): void
    {
        $mix = new ExploreMixService;
        $post = new Post([
            'metrics' => ['views' => 1_000_000, 'likes' => 50_000],
        ]);
        $post->setRelation('winnerInsight', new WinnerInsight(['score' => 72.5]));

        $this->assertSame(72.5, $mix->qualityScore($post));
    }

    #[Test]
    public function mix_keeps_weak_posts_after_strong_set(): void
    {
        config([
            'snitch.explore.mix_enabled' => true,
            'snitch.explore.min_quality_ratio' => 0.5,
            'snitch.explore.weight_exponent' => 1.5,
            'snitch.explore.jitter' => 0.4,
        ]);

        $mix = new ExploreMixService;
        $ordered = $mix->mix([
            1 => 100.0,
            2 => 90.0,
            3 => 80.0,
            4 => 10.0,
            5 => 5.0,
        ], seed: 42);

        $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5], $ordered);
        $this->assertSame([4, 5], array_slice($ordered, 3));
        $this->assertEqualsCanonicalizing([1, 2, 3], array_slice($ordered, 0, 3));
    }

    #[Test]
    public function mix_rotates_among_strong_peers_across_seeds(): void
    {
        config([
            'snitch.explore.mix_enabled' => true,
            'snitch.explore.min_quality_ratio' => 0.2,
            'snitch.explore.weight_exponent' => 1.2,
            'snitch.explore.jitter' => 0.9,
        ]);

        $mix = new ExploreMixService;
        $scores = [
            10 => 88.0,
            11 => 86.0,
            12 => 84.0,
            13 => 82.0,
            14 => 80.0,
        ];

        $a = $mix->mix($scores, seed: 1);
        $b = $mix->mix($scores, seed: 99);

        $this->assertNotSame($a, $b);
        $this->assertEqualsCanonicalizing(array_keys($scores), $a);
        $this->assertEqualsCanonicalizing(array_keys($scores), $b);
    }

    #[Test]
    public function mix_is_stable_for_the_same_seed(): void
    {
        config([
            'snitch.explore.mix_enabled' => true,
            'snitch.explore.min_quality_ratio' => 0.2,
            'snitch.explore.weight_exponent' => 1.4,
            'snitch.explore.jitter' => 0.7,
        ]);

        $mix = new ExploreMixService;
        $scores = [
            1 => 95.0,
            2 => 90.0,
            3 => 85.0,
            4 => 80.0,
            5 => 20.0,
        ];

        $this->assertSame($mix->mix($scores, seed: 12345), $mix->mix($scores, seed: 12345));
    }

    #[Test]
    public function mix_semantic_pins_exact_matches_ahead_of_related(): void
    {
        config([
            'snitch.explore.mix_enabled' => true,
            'snitch.explore.min_quality_ratio' => 0.2,
            'snitch.explore.weight_exponent' => 1.5,
            'snitch.explore.jitter' => 0.5,
        ]);

        $mix = new ExploreMixService;
        $ordered = $mix->mixSemantic([
            1 => 1.0,
            2 => 0.91,
            3 => 0.88,
            4 => 0.4,
        ], pinnedIds: [1], seed: 7);

        $this->assertSame(1, $ordered[0]);
        $this->assertContains(2, $ordered);
        $this->assertContains(3, $ordered);
    }

    #[Test]
    public function resolve_seed_reuses_explicit_query_value(): void
    {
        $mix = new ExploreMixService;

        $this->assertSame(42, $mix->resolveSeed('42', userId: 9, hasQueryParams: true));
        $this->assertSame(42, $mix->resolveSeed(42, userId: 9, hasQueryParams: false));
    }

    #[Test]
    public function resolve_seed_mints_fresh_seed_on_bare_visit(): void
    {
        $mix = new ExploreMixService;

        $a = $mix->resolveSeed(null, userId: 9, hasQueryParams: false, entropy: 100);
        $b = $mix->resolveSeed(null, userId: 9, hasQueryParams: false, entropy: 101);
        $again = $mix->resolveSeed(null, userId: 9, hasQueryParams: false, entropy: 100);

        $this->assertNotSame($a, $b);
        $this->assertSame($a, $again);
        $this->assertSame($a, $mix->freshSeed(9, 100));
        $this->assertNotSame($a, $mix->seedFor(9, 1_700_000_000));
    }

    #[Test]
    public function resolve_seed_uses_bucket_when_query_present_without_seed(): void
    {
        config(['snitch.explore.seed_bucket_hours' => 6]);

        $mix = new ExploreMixService;
        $t0 = 1_700_000_000;

        $bucket = $mix->resolveSeed(null, userId: 9, hasQueryParams: true, now: $t0);
        $same = $mix->resolveSeed(null, userId: 9, hasQueryParams: true, now: $t0 + 60);
        $later = $mix->resolveSeed(null, userId: 9, hasQueryParams: true, now: $t0 + (6 * 3600) + 1);

        $this->assertSame($mix->seedFor(9, $t0), $bucket);
        $this->assertSame($bucket, $same);
        $this->assertNotSame($bucket, $later);
    }

    #[Test]
    public function seed_for_is_stable_inside_bucket_and_changes_later(): void
    {
        config(['snitch.explore.seed_bucket_hours' => 6]);

        $mix = new ExploreMixService;
        $t0 = 1_700_000_000;
        $same = $mix->seedFor(9, $t0 + 60);
        $later = $mix->seedFor(9, $t0 + (6 * 3600) + 1);

        $this->assertSame($mix->seedFor(9, $t0), $same);
        $this->assertNotSame($same, $later);
    }
}
