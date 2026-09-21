<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\Post;
use App\Services\Tracking\PostCoverHydrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snitch:backfill-covers
    {--platform=* : Restrict to platforms (instagram, tiktok, youtube, facebook, linkedin)}
    {--limit=0 : Max posts to process (0 = all)}
    {--fetch : Call TikTok oEmbed when the payload has no still}
    {--force : Recompute even when cover_url is already stored}
    {--dry-run : Report matches without writing}')]
#[Description('Persist still covers for existing posts from payload, YouTube thumbs, or TikTok oEmbed')]
class BackfillPostCoversCommand extends Command
{
    public function handle(PostCoverHydrator $hydrator): int
    {
        $platforms = $this->resolvePlatforms();
        $limit = max(0, (int) $this->option('limit'));
        $fetch = (bool) $this->option('fetch');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $query = Post::query()->orderBy('id');

        if ($platforms !== []) {
            $query->whereIn('platform', $platforms);
        }

        if (! $force) {
            $query->where(function ($inner): void {
                $inner->whereNull('cover_url')->orWhere('cover_url', '');
            });
        }

        $scanned = 0;
        $written = 0;
        $skipped = 0;
        $missing = 0;

        $query->chunkById(50, function ($posts) use (
            $hydrator,
            $fetch,
            $dryRun,
            $limit,
            &$scanned,
            &$written,
            &$skipped,
            &$missing,
        ): bool {
            foreach ($posts as $post) {
                if (! $post instanceof Post) {
                    continue;
                }

                if ($limit > 0 && $scanned >= $limit) {
                    return false;
                }

                $scanned++;

                if ($dryRun) {
                    $url = $hydrator->discover($post, fetchRemote: $fetch);

                    if ($url === null) {
                        $missing++;
                        $this->line("post #{$post->id}: none");
                    } else {
                        $written++;
                        $this->line("post #{$post->id}: {$url}");
                    }

                    continue;
                }

                $before = $post->getRawOriginal('cover_url');
                $url = $hydrator->persist($post, fetchRemote: $fetch);

                if ($url === null) {
                    $missing++;

                    continue;
                }

                if ($before !== $url) {
                    $written++;
                    $this->line("post #{$post->id}: {$url}");

                    continue;
                }

                $skipped++;
            }

            return $limit === 0 || $scanned < $limit;
        });

        $this->info("Scanned {$scanned}; wrote {$written}; skipped {$skipped}; missing {$missing}.");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolvePlatforms(): array
    {
        $raw = $this->option('platform');
        $values = is_array($raw) ? $raw : [];
        $platforms = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $platform = Platform::tryFrom(strtolower(trim($value)));

            if ($platform !== null) {
                $platforms[] = $platform->value;
            }
        }

        return $platforms;
    }
}
