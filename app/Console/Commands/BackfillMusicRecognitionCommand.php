<?php

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\PostAnalysis;
use App\Services\Analysis\PlatformMusicExtractor;
use App\Services\Music\MusicRecognitionService;
use App\Services\Music\SpotifyLinkResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('snitch:backfill-music-recognition
    {--platform=* : Restrict to platforms (instagram, tiktok, youtube, facebook, linkedin)}
    {--limit=25 : Maximum posts to process}
    {--only-missing=1 : Only run when the analysis music payload is missing or model-guess}
    {--force : Re-run recognition even when a provider result is already stored}
    {--post-id= : Restrict to a single post id}
    {--platform-metadata-only : Refresh from platform metadata, do not call AcoustID / AudD}
    {--enrich-spotify-only : Skip recognition and only add spotify_* fields to existing music payloads}
    {--dry-run : Report which posts would be processed without touching them}
    {--verbose-clip : Print clip diagnostics (sha, mean dBFS) when available}')]
#[Description('Back-run song ID (platform > AcoustID > AudD) on completed reel analyses')]
class BackfillMusicRecognitionCommand extends Command
{
    public function handle(
        MusicRecognitionService $recognition,
        PlatformMusicExtractor $platformExtractor,
        SpotifyLinkResolver $spotifyResolver,
    ): int {
        $platforms = $this->resolvePlatforms();
        $limit = max(1, (int) $this->option('limit'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $onlyMissing = filter_var($this->option('only-missing'), FILTER_VALIDATE_BOOLEAN);
        $platformOnly = (bool) $this->option('platform-metadata-only');
        $spotifyOnly = (bool) $this->option('enrich-spotify-only');
        $singleId = $this->option('post-id');

        $query = PostAnalysis::query()
            ->with(['post.socialAccount'])
            ->where('status', AnalysisStatus::Completed)
            ->whereHas('post', function ($q) use ($platforms) {
                $q->whereIn('type', [PostType::Reel->value, PostType::Video->value])
                    ->whereNotNull('media_url');

                if ($platforms !== []) {
                    $q->whereIn('platform', $platforms);
                }
            })
            ->orderByDesc('analyzed_at');

        if ($singleId !== null && $singleId !== '') {
            $query->where('post_id', (int) $singleId);
        }

        $analyses = $query->limit($limit)->get();

        if ($analyses->isEmpty()) {
            $this->info('No completed reel analyses match the filters.');

            return self::SUCCESS;
        }

        $scanned = 0;
        $identified = 0;
        $unresolved = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($analyses as $analysis) {
            $scanned++;
            $post = $analysis->post;

            if ($post === null) {
                continue;
            }

            $existing = is_array($analysis->music) ? $analysis->music : null;

            if ($spotifyOnly) {
                if ($existing === null || $existing === []) {
                    $skipped++;

                    continue;
                }

                if (isset($existing['spotify_track_id']) && ! $force) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        ' would-enrich post=%d %s title=%s',
                        $post->id,
                        $post->platform instanceof Platform ? $post->platform->value : 'unknown',
                        $existing['title'] ?? '-',
                    ));

                    continue;
                }

                try {
                    $resolved = $spotifyResolver->resolve([
                        'title' => $existing['title'] ?? null,
                        'artist' => $existing['artist'] ?? null,
                        'isrc' => $existing['isrc'] ?? null,
                        'spotify_track_id' => $existing['spotify_track_id'] ?? null,
                        'spotify_url' => $existing['spotify_url'] ?? null,
                        'is_original_audio' => $existing['is_original_audio'] ?? null,
                    ]);
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn(sprintf('  post=%d spotify resolve threw: %s', $post->id, $e->getMessage()));

                    continue;
                }

                if ($resolved === null) {
                    $unresolved++;
                    $this->line(sprintf(' no-spotify post=%d title=%s', $post->id, $existing['title'] ?? '-'));

                    continue;
                }

                $existing['spotify_track_id'] = $resolved['spotify_track_id'];
                $existing['spotify_url'] = $resolved['spotify_url'];
                $existing['spotify_embed_url'] = $resolved['spotify_embed_url'];
                $existing['spotify_resolved_via'] = $resolved['resolved_via'];

                $analysis->music = array_filter($existing, static fn ($value) => $value !== null);
                $analysis->save();

                $identified++;
                $this->info(sprintf(
                    '     spotify post=%d via=%s url=%s',
                    $post->id,
                    $resolved['resolved_via'],
                    $resolved['spotify_url'],
                ));

                continue;
            }

            if (! $force && $this->shouldSkipExisting($existing, $onlyMissing)) {
                $skipped++;
                $this->line(sprintf(
                    '   skip post=%d %s existing=%s',
                    $post->id,
                    $post->platform instanceof Platform ? $post->platform->value : 'unknown',
                    $existing['source'] ?? 'none',
                ));

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    ' would-recognise post=%d %s existing=%s',
                    $post->id,
                    $post->platform instanceof Platform ? $post->platform->value : 'unknown',
                    $existing['source'] ?? 'none',
                ));

                continue;
            }

            try {
                $result = $platformOnly
                    ? $platformExtractor->fromPost($post)
                    : $recognition->recognize($post);
            } catch (Throwable $e) {
                $failed++;
                $this->warn(sprintf('  post=%d recognition threw: %s', $post->id, $e->getMessage()));

                continue;
            }

            if ($result === null) {
                $unresolved++;
                $this->line(sprintf(' unresolved post=%d %s', $post->id, $post->platform?->value));

                continue;
            }

            $analysis->music = array_filter($result, static fn ($value) => $value !== null);
            $analysis->save();

            $identified++;
            $summary = sprintf(
                '     hit post=%d %s source=%s title=%s artist=%s',
                $post->id,
                $post->platform?->value,
                $result['source'] ?? 'unknown',
                $result['title'] ?? '-',
                $result['artist'] ?? '-',
            );

            if (isset($result['confidence'])) {
                $summary .= ' conf='.number_format((float) $result['confidence'], 2);
            }

            if (isset($result['isrc'])) {
                $summary .= ' isrc='.$result['isrc'];
            }

            $this->info($summary);
        }

        $this->newLine();
        $this->info(sprintf(
            'Scanned %d; identified %d; unresolved %d; skipped %d; failed %d.',
            $scanned,
            $identified,
            $unresolved,
            $skipped,
            $failed,
        ));

        return $failed === $scanned && $scanned > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolvePlatforms(): array
    {
        $raw = (array) $this->option('platform');
        $out = [];

        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }
            $value = trim(strtolower($value));
            if ($value === '') {
                continue;
            }
            $out[] = $value;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>|null  $existing
     */
    private function shouldSkipExisting(?array $existing, bool $onlyMissing): bool
    {
        if (! $onlyMissing) {
            return false;
        }

        if ($existing === null || $existing === []) {
            return false;
        }

        $source = $existing['source'] ?? null;

        if (! is_string($source) || $source === '' || $source === 'model' || $source === 'unresolved') {
            return false;
        }

        return true;
    }
}
