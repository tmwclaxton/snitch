<?php

namespace App\Console\Commands;

use App\Exceptions\InsufficientInfluencerSuggestionsException;
use App\Jobs\FindInfluencersJob;
use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Influencers\InfluencerDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProbeInfluencerFindCommand extends Command
{
    protected $signature = 'snitch:probe-influencer-find
        {user? : User id or email (defaults to first user with a brand profile)}
        {--platforms=instagram,tiktok,youtube : Comma-separated platforms}
        {--language=English : Language hint}
        {--min-followers= : Minimum followers}
        {--max-followers= : Maximum followers}
        {--brief= : Discovery brief (auto-generates when empty)}
        {--name= : Temporarily override brand name for this probe}
        {--description= : Temporarily override brand description for this probe}
        {--sync : Run discovery inline instead of queueing}';

    protected $description = 'Probe influencer discovery (Firecrawl + NanoGPT + Apify) for a brand user.';

    public function handle(InfluencerDiscoveryService $discovery): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            $this->error('No user with a brand profile found.');

            return self::FAILURE;
        }

        $brand = $user->brandProfile;

        if ($brand === null) {
            $this->error('User has no brand profile.');

            return self::FAILURE;
        }

        $originalName = $brand->name;
        $originalDescription = $brand->description;

        if ($this->option('name') !== null && $this->option('name') !== '') {
            $brand->name = (string) $this->option('name');
        }

        if ($this->option('description') !== null) {
            $brand->description = (string) $this->option('description');
        }

        $platforms = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('platforms')),
        )));

        $filters = [
            'platforms' => $platforms,
            'language' => $this->option('language') ?: null,
            'min_followers' => $this->option('min-followers') !== null && $this->option('min-followers') !== ''
                ? (int) $this->option('min-followers')
                : null,
            'max_followers' => $this->option('max-followers') !== null && $this->option('max-followers') !== ''
                ? (int) $this->option('max-followers')
                : null,
            'brief' => trim((string) $this->option('brief')),
        ];

        if ($filters['brief'] === '') {
            $this->info('Generating brief...');
            $filters['brief'] = $discovery->generateBrief($brand, $filters);
            $this->line($filters['brief']);
        }

        $this->info("Brand: {$brand->name} (user {$user->id})");
        $this->info('Platforms: '.implode(', ', $platforms));

        if ($this->option('sync')) {
            try {
                $rows = $discovery->discover($brand, $filters, function (array $partial): void {
                    $this->comment('Partial: '.count($partial).' verified...');
                });
            } catch (\Throwable $exception) {
                $this->error($exception->getMessage());

                if ($exception instanceof InsufficientInfluencerSuggestionsException) {
                    foreach ($exception->suggestions as $row) {
                        $this->line($this->formatRow($row));
                    }
                }

                $this->restoreBrand($brand, $originalName, $originalDescription);

                return self::FAILURE;
            }

            foreach ($rows as $row) {
                $this->line($this->formatRow($row));
            }

            $this->info('Done: '.count($rows).' influencers.');
            $this->restoreBrand($brand, $originalName, $originalDescription);

            return self::SUCCESS;
        }

        $runId = (string) Str::uuid();

        Cache::put(FindInfluencersJob::cacheKeyFor($user->id, $runId), [
            'status' => 'pending',
            'filters' => $filters,
            'brief' => $filters['brief'],
            'suggestions' => [],
            'decisions' => [],
            'error' => null,
        ], now()->addHours(2));

        Cache::put(FindInfluencersJob::activeCacheKeyFor($user->id), $runId, now()->addHours(2));
        FindInfluencersJob::dispatch($user->id, $runId, $filters);

        $this->info("Queued FindInfluencersJob run {$runId}");
        $this->restoreBrand($brand, $originalName, $originalDescription);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function formatRow(array $row): string
    {
        $followers = $row['followers'] ?? 'unknown';

        return sprintf(
            '- %s @%s (%s) followers=%s seed=%s source=%s',
            $row['platform'] ?? '?',
            $row['handle'] ?? '?',
            $row['display_name'] ?? '',
            is_scalar($followers) ? (string) $followers : 'unknown',
            $row['seed'] ?? '?',
            is_string($row['source'] ?? null) ? Str::limit($row['source'], 40, '') : '?',
        );
    }

    private function restoreBrand(BrandProfile $brand, string $name, ?string $description): void
    {
        $brand->name = $name;
        $brand->description = $description;
    }

    private function resolveUser(): ?User
    {
        $arg = $this->argument('user');

        if ($arg === null) {
            return User::query()
                ->whereHas('brandProfile')
                ->orderBy('id')
                ->first();
        }

        if (is_numeric($arg)) {
            return User::query()->find((int) $arg);
        }

        return User::query()->where('email', $arg)->first();
    }
}
