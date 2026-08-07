<?php

namespace App\Jobs;

use App\Services\Onboarding\BrandWebsiteAutofillService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AutofillBrandFromWebsiteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [1, 5];

    public int $timeout = 90;

    public function __construct(
        public int $userId,
        public string $autofillId,
        public string $website,
    ) {}

    public function handle(BrandWebsiteAutofillService $autofill): void
    {
        $this->putStatus([
            'status' => 'processing',
            'website' => $this->website,
            'fields' => null,
            'error' => null,
        ]);

        $fields = $autofill->extract($this->website);

        $this->putStatus([
            'status' => 'completed',
            'website' => $this->website,
            'fields' => $fields,
            'error' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('AutofillBrandFromWebsiteJob failed', [
            'user_id' => $this->userId,
            'autofill_id' => $this->autofillId,
            'website' => $this->website,
            'error' => $exception?->getMessage(),
        ]);

        $this->putStatus([
            'status' => 'failed',
            'website' => $this->website,
            'fields' => null,
            'error' => $exception?->getMessage() ?: 'Unable to autofill from website.',
        ]);
    }

    /**
     * @param  array{status: string, website: string, fields: ?array<string, mixed>, error: ?string}  $payload
     */
    private function putStatus(array $payload): void
    {
        Cache::put($this->cacheKey(), $payload, now()->addMinutes(15));
    }

    public static function cacheKeyFor(int $userId, string $autofillId): string
    {
        return "brand-autofill:{$userId}:{$autofillId}";
    }

    private function cacheKey(): string
    {
        return self::cacheKeyFor($this->userId, $this->autofillId);
    }
}
