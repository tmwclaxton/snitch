<?php

namespace Tests\Feature;

use App\Jobs\SuggestCompetitorsJob;
use App\Models\BrandProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

class SuggestCompetitorsJobFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_survives_uninitialized_filters_property(): void
    {
        $user = User::factory()->create();
        BrandProfile::factory()->for($user)->create();
        $suggestId = (string) Str::uuid();

        $job = (new ReflectionClass(SuggestCompetitorsJob::class))->newInstanceWithoutConstructor();
        $job->userId = $user->id;
        $job->suggestId = $suggestId;

        $job->failed(new \RuntimeException('simulated worker crash'));

        $payload = Cache::get(SuggestCompetitorsJob::cacheKeyFor($user->id, $suggestId));

        $this->assertIsArray($payload);
        $this->assertSame('failed', $payload['status']);
        $this->assertSame([], $payload['filters']);
        $this->assertSame('simulated worker crash', $payload['error']);
    }
}
