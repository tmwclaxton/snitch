<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ScoreWinnersJob;
use App\Services\Winners\WinnerScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Tests\TestCase;

class ScoreWinnersJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_unserialized_job_without_run_id_can_report_failure(): void
    {
        $job = (new ReflectionClass(ScoreWinnersJob::class))->newInstanceWithoutConstructor();
        $job->userId = 1;

        $job->failed(new \RuntimeException('boom'));

        $this->assertNotSame('', $job->runId);
        $this->assertSame(
            [
                'status' => 'failed',
                'error' => 'boom',
                'winner_count' => null,
            ],
            ScoreWinnersJob::statusFor(1, $job->runId),
        );
        $this->assertNull(Cache::get(ScoreWinnersJob::activeCacheKeyFor(1)));
    }

    public function test_handle_generates_run_id_when_missing(): void
    {
        $scorer = $this->createMock(WinnerScorer::class);
        $scorer->expects($this->never())->method('rescoreUser');

        $job = (new ReflectionClass(ScoreWinnersJob::class))->newInstanceWithoutConstructor();
        $job->userId = 999_999;

        $job->handle($scorer);

        $this->assertNotSame('', $job->runId);
        $this->assertSame(
            [
                'status' => 'failed',
                'error' => 'User not found.',
                'winner_count' => null,
            ],
            ScoreWinnersJob::statusFor(999_999, $job->runId),
        );
    }
}
