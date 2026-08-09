<?php

namespace Tests\Unit\Support;

use App\Support\CosineSimilarity;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CosineSimilarityTest extends TestCase
{
    #[Test]
    public function identical_vectors_score_one(): void
    {
        $this->assertEqualsWithDelta(1.0, CosineSimilarity::score([1, 0, 0], [1, 0, 0]), 0.0001);
    }

    #[Test]
    public function orthogonal_vectors_score_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, CosineSimilarity::score([1, 0], [0, 1]), 0.0001);
    }

    #[Test]
    public function empty_vectors_score_zero(): void
    {
        $this->assertSame(0.0, CosineSimilarity::score([], [1.0]));
    }
}
