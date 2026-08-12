<?php

namespace Tests\Unit\Support;

use App\Support\SyncOptions;
use Tests\TestCase;

class SyncOptionsTest extends TestCase
{
    public function test_resolves_config_defaults_when_options_omitted(): void
    {
        config([
            'snitch.sync.posts_limit' => 12,
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit_max' => 50,
            'snitch.sync.recency_days_max' => 90,
        ]);

        $options = new SyncOptions;

        $this->assertSame(12, $options->resolvedPostsLimit());
        $this->assertSame(30, $options->resolvedRecencyDays());
    }

    public function test_clamps_custom_values_to_configured_maximums(): void
    {
        config([
            'snitch.sync.posts_limit' => 12,
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit_max' => 50,
            'snitch.sync.recency_days_max' => 90,
        ]);

        $options = new SyncOptions(postsLimit: 100, recencyDays: 365);

        $this->assertSame(50, $options->resolvedPostsLimit());
        $this->assertSame(90, $options->resolvedRecencyDays());
    }

    public function test_from_validated_accepts_partial_overrides(): void
    {
        $options = SyncOptions::fromValidated([
            'posts_limit' => 24,
        ]);

        config([
            'snitch.sync.posts_limit' => 12,
            'snitch.sync.recency_days' => 30,
            'snitch.sync.posts_limit_max' => 50,
            'snitch.sync.recency_days_max' => 90,
        ]);

        $this->assertSame(24, $options->resolvedPostsLimit());
        $this->assertSame(30, $options->resolvedRecencyDays());
    }
}
