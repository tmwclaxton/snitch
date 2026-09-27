<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Models\FollowerSnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Services\Tracking\FollowerCountRefresher;
use App\Support\InstagramPostId;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('snitch:repair-engagement-data
    {--dry-run : Report planned writes without changing rows}
    {--fix-views : Rewrite Instagram metrics.views from raw play counts when higher}
    {--merge-duplicates : Merge Instagram posts that share a shortcode but differ by external_id}
    {--backfill-followers : Copy latest known follower counts onto trackers with null followers}
    {--remove-fabricated-baselines : Delete flat week/month follower snapshot anchors}
    {--all : Run every repair step}')]
#[Description('Idempotent data repair for impossible engagement rates, duplicate Instagram posts, and missing tracker follower counts')]
class RepairEngagementDataCommand extends Command
{
    public function handle(FollowerCountRefresher $followers): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all');
        $fixViews = $all || (bool) $this->option('fix-views');
        $mergeDuplicates = $all || (bool) $this->option('merge-duplicates');
        $backfillFollowers = $all || (bool) $this->option('backfill-followers');
        $removeBaselines = $all || (bool) $this->option('remove-fabricated-baselines');

        if (! $fixViews && ! $mergeDuplicates && ! $backfillFollowers && ! $removeBaselines) {
            $this->error('Pass --all or one of --fix-views / --merge-duplicates / --backfill-followers / --remove-fabricated-baselines.');
            $this->line('Always dry-run first: php artisan snitch:repair-engagement-data --all --dry-run');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry run - no rows will be written.');
        }

        $viewsFixed = $fixViews ? $this->fixViews($dryRun) : 0;
        $duplicatesMerged = $mergeDuplicates ? $this->mergeDuplicates($dryRun) : 0;
        $followersBackfilled = $backfillFollowers ? $this->backfillFollowers($followers, $dryRun) : 0;
        $baselinesRemoved = $removeBaselines ? $this->removeFabricatedBaselines($dryRun) : 0;

        $this->newLine();
        $this->info(sprintf(
            'Done%s. views_fixed=%d duplicates_merged=%d followers_backfilled=%d baselines_removed=%d',
            $dryRun ? ' (dry-run)' : '',
            $viewsFixed,
            $duplicatesMerged,
            $followersBackfilled,
            $baselinesRemoved,
        ));

        return self::SUCCESS;
    }

    private function fixViews(bool $dryRun): int
    {
        $fixed = 0;

        Post::query()
            ->where('platform', Platform::Instagram)
            ->orderBy('id')
            ->chunkById(100, function (Collection $posts) use ($dryRun, &$fixed): void {
                foreach ($posts as $post) {
                    if (! $post instanceof Post) {
                        continue;
                    }

                    $raw = is_array($post->raw_payload) ? $post->raw_payload : null;
                    $playCount = InstagramPostId::playCountFromRaw($raw);

                    if ($playCount === null) {
                        continue;
                    }

                    $metrics = is_array($post->metrics) ? $post->metrics : [];
                    $storedViews = (int) ($metrics['views'] ?? 0);

                    if ($playCount <= $storedViews) {
                        continue;
                    }

                    $fixed++;
                    $this->line("post #{$post->id}: views {$storedViews} -> {$playCount}");

                    if ($dryRun) {
                        continue;
                    }

                    $metrics['views'] = $playCount;
                    $post->forceFill(['metrics' => $metrics])->save();
                }
            });

        return $fixed;
    }

    private function mergeDuplicates(bool $dryRun): int
    {
        $merged = 0;
        $groups = [];

        Post::query()
            ->where('platform', Platform::Instagram)
            ->orderBy('id')
            ->chunkById(100, function (Collection $posts) use (&$groups): void {
                foreach ($posts as $post) {
                    if (! $post instanceof Post) {
                        continue;
                    }

                    $code = InstagramPostId::fromUrl((string) $post->url)
                        ?? (ctype_alnum((string) $post->external_id) && ! ctype_digit((string) $post->external_id)
                            ? (string) $post->external_id
                            : null);

                    if ($code === null || $code === '') {
                        continue;
                    }

                    $key = $post->social_account_id.'|'.$code;
                    $groups[$key][] = $post->id;
                }
            });

        foreach ($groups as $key => $ids) {
            $ids = array_values(array_unique($ids));

            if (count($ids) < 2) {
                continue;
            }

            $posts = Post::query()
                ->with('analysis')
                ->whereIn('id', $ids)
                ->get();

            if ($posts->count() < 2) {
                continue;
            }

            $keeper = $this->pickKeeper($posts);
            $code = explode('|', (string) $key, 2)[1] ?? (string) $keeper->external_id;

            foreach ($posts as $post) {
                if ((int) $post->id === (int) $keeper->id) {
                    continue;
                }

                $merged++;
                $this->line("merge post #{$post->id} -> #{$keeper->id} (shortcode {$code})");

                if ($dryRun) {
                    continue;
                }

                $this->reassignChildRows((int) $post->id, (int) $keeper->id);
                $this->preferMetrics($keeper, $post);
                $post->delete();
            }

            if (! $dryRun) {
                $keeper->forceFill([
                    'external_id' => $code,
                    'metrics' => $keeper->metrics,
                ])->save();
            } else {
                $this->line("would set keeper #{$keeper->id} external_id={$code}");
            }
        }

        return $merged;
    }

    /**
     * @param  Collection<int, Post>  $posts
     */
    private function pickKeeper(Collection $posts): Post
    {
        $withCompleted = $posts->first(
            fn (Post $post): bool => $post->analysis?->status === AnalysisStatus::Completed,
        );

        if ($withCompleted instanceof Post) {
            return $withCompleted;
        }

        return $posts
            ->sortByDesc(function (Post $post): int {
                $metrics = is_array($post->metrics) ? $post->metrics : [];

                return (int) ($metrics['views'] ?? 0);
            })
            ->first() ?? $posts->first();
    }

    private function preferMetrics(Post $keeper, Post $donor): void
    {
        $keeperMetrics = is_array($keeper->metrics) ? $keeper->metrics : [];
        $donorMetrics = is_array($donor->metrics) ? $donor->metrics : [];

        foreach (['views', 'likes', 'comments', 'shares', 'clicks'] as $key) {
            $keeperValue = (int) ($keeperMetrics[$key] ?? 0);
            $donorValue = (int) ($donorMetrics[$key] ?? 0);

            if ($donorValue > $keeperValue) {
                $keeperMetrics[$key] = $donorValue;
            }
        }

        $keeper->metrics = $keeperMetrics;
    }

    private function reassignChildRows(int $fromPostId, int $toPostId): void
    {
        $fromAnalysis = DB::table('post_analyses')->where('post_id', $fromPostId)->first();
        $toAnalysis = DB::table('post_analyses')->where('post_id', $toPostId)->first();

        if ($fromAnalysis !== null && $toAnalysis === null) {
            DB::table('post_analyses')->where('id', $fromAnalysis->id)->update([
                'post_id' => $toPostId,
            ]);
        } elseif ($fromAnalysis !== null && $toAnalysis !== null) {
            $fromCompleted = ($fromAnalysis->status ?? null) === AnalysisStatus::Completed->value;
            $toCompleted = ($toAnalysis->status ?? null) === AnalysisStatus::Completed->value;

            if ($fromCompleted && ! $toCompleted) {
                DB::table('analysis_term_post_analysis')->where('post_analysis_id', $toAnalysis->id)->delete();
                DB::table('post_analyses')->where('id', $toAnalysis->id)->delete();
                DB::table('post_analyses')->where('id', $fromAnalysis->id)->update([
                    'post_id' => $toPostId,
                ]);
            } else {
                DB::table('analysis_term_post_analysis')->where('post_analysis_id', $fromAnalysis->id)->delete();
                DB::table('post_analyses')->where('id', $fromAnalysis->id)->delete();
            }
        }

        $insights = DB::table('winner_insights')->where('post_id', $fromPostId)->get();

        foreach ($insights as $insight) {
            $exists = DB::table('winner_insights')
                ->where('user_id', $insight->user_id)
                ->where('post_id', $toPostId)
                ->exists();

            if ($exists) {
                DB::table('winner_insights')->where('id', $insight->id)->delete();
            } else {
                DB::table('winner_insights')->where('id', $insight->id)->update([
                    'post_id' => $toPostId,
                ]);
            }
        }
    }

    private function backfillFollowers(FollowerCountRefresher $followers, bool $dryRun): int
    {
        $updated = 0;

        SocialAccount::query()
            ->whereHas('trackedAccounts', fn ($query) => $query->whereNull('followers'))
            ->orderBy('id')
            ->chunkById(50, function (Collection $accounts) use ($followers, $dryRun, &$updated): void {
                foreach ($accounts as $social) {
                    if (! $social instanceof SocialAccount) {
                        continue;
                    }

                    $latest = $followers->latestKnown($social->id);

                    if ($latest === null) {
                        continue;
                    }

                    $nullCount = TrackedAccount::query()
                        ->where('social_account_id', $social->id)
                        ->whereNull('followers')
                        ->count();

                    if ($nullCount === 0) {
                        continue;
                    }

                    $updated += $nullCount;
                    $this->line("social #{$social->id}: seed {$nullCount} tracker(s) with {$latest} followers");

                    if ($dryRun) {
                        continue;
                    }

                    $followers->seedMissingTrackers($social->id);
                }
            });

        return $updated;
    }

    private function removeFabricatedBaselines(bool $dryRun): int
    {
        $removed = 0;

        SocialAccount::query()
            ->whereHas('followerSnapshots')
            ->orderBy('id')
            ->chunkById(50, function (Collection $accounts) use ($dryRun, &$removed): void {
                foreach ($accounts as $social) {
                    if (! $social instanceof SocialAccount) {
                        continue;
                    }

                    $snapshots = FollowerSnapshot::query()
                        ->where('social_account_id', $social->id)
                        ->orderBy('captured_on')
                        ->get();

                    if ($snapshots->count() < 2) {
                        continue;
                    }

                    $byDay = [];

                    foreach ($snapshots as $snapshot) {
                        $day = $snapshot->captured_on?->toDateString();

                        if ($day === null) {
                            continue;
                        }

                        $byDay[$day] = $snapshot;
                    }

                    foreach ($byDay as $day => $snapshot) {
                        foreach ([7, 30] as $offset) {
                            try {
                                $anchorDay = CarbonImmutable::parse($day)->addDays($offset)->toDateString();
                            } catch (Throwable) {
                                continue;
                            }

                            $anchor = $byDay[$anchorDay] ?? null;

                            if ($anchor === null) {
                                continue;
                            }

                            if ((int) $snapshot->followers !== (int) $anchor->followers) {
                                continue;
                            }

                            // Flat planted baseline: same count exactly 7 or 30 days before
                            // another observation. Platforms only return the current count.
                            $removed++;
                            $this->line("snapshot #{$snapshot->id}: remove fabricated baseline {$day} (= {$anchorDay})");

                            if (! $dryRun) {
                                $snapshot->delete();
                                unset($byDay[$day]);
                            }
                        }
                    }
                }
            });

        return $removed;
    }
}
