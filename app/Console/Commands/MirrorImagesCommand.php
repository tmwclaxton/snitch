<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use App\Services\Apify\PlatformAdapterManager;
use App\Services\Tracking\AvatarArchive;
use App\Services\Tracking\AvatarMirror;
use App\Services\Tracking\PostCoverHydrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('snitch:mirror-images
    {--tracked=* : Restrict to tracked_account ids}
    {--user= : Restrict to a user id (their tracked accounts + posts)}
    {--posts : Mirror post covers}
    {--avatars : Mirror social / tracked avatars}
    {--fetch : Refresh remote sources when the stored URL 403s (covers via platform fallbacks; avatars via profile resolve)}
    {--limit=0 : Max rows per resource (0 = all)}
    {--dry-run : Report matches without writing}')]
#[Description('Copy post covers and account avatars onto the public disk so signed CDN links cannot expire in the UI')]
class MirrorImagesCommand extends Command
{
    public function handle(
        PostCoverHydrator $covers,
        AvatarMirror $avatars,
        AvatarArchive $avatarArchive,
        PlatformAdapterManager $adapters,
    ): int {
        $doPosts = (bool) $this->option('posts');
        $doAvatars = (bool) $this->option('avatars');

        if (! $doPosts && ! $doAvatars) {
            $doPosts = true;
            $doAvatars = true;
        }

        $fetch = (bool) $this->option('fetch');
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $exit = self::SUCCESS;

        if ($doPosts) {
            $exit = $this->mirrorPosts($covers, $fetch, $dryRun, $limit) === self::SUCCESS
                ? $exit
                : self::FAILURE;
        }

        if ($doAvatars) {
            $exit = $this->mirrorAvatars($avatars, $avatarArchive, $adapters, $fetch, $dryRun, $limit) === self::SUCCESS
                ? $exit
                : self::FAILURE;
        }

        return $exit;
    }

    private function mirrorPosts(PostCoverHydrator $covers, bool $fetch, bool $dryRun, int $limit): int
    {
        $query = Post::query()->orderBy('id');
        $this->constrainPosts($query);

        $scanned = 0;
        $mirrored = 0;
        $already = 0;
        $failed = 0;

        $query->chunkById(50, function ($posts) use (
            $covers,
            $fetch,
            $dryRun,
            $limit,
            &$scanned,
            &$mirrored,
            &$already,
            &$failed,
        ): bool {
            foreach ($posts as $post) {
                if (! $post instanceof Post) {
                    continue;
                }

                if ($limit > 0 && $scanned >= $limit) {
                    return false;
                }

                $scanned++;
                $before = $post->getRawOriginal('cover_url');

                if (! $covers->needsMirroring($post) && ! $fetch) {
                    $already++;
                    $this->line("post #{$post->id}: already-local {$before}");

                    continue;
                }

                if ($dryRun) {
                    $url = $covers->discover($post, fetchRemote: $fetch);
                    if ($url === null) {
                        $failed++;
                        $this->line("post #{$post->id}: failed (none)");
                    } else {
                        $mirrored++;
                        $this->line("post #{$post->id}: would-mirror {$url}");
                    }

                    continue;
                }

                $url = $covers->persist($post, fetchRemote: $fetch);
                $after = $post->fresh()?->getRawOriginal('cover_url');

                if ($url === null) {
                    $failed++;
                    $this->line("post #{$post->id}: failed (none)");

                    continue;
                }

                if ($before !== $after && is_string($after) && str_starts_with($after, '/storage/')) {
                    $mirrored++;
                    $this->line("post #{$post->id}: mirrored {$after}");

                    continue;
                }

                if (is_string($after) && (str_starts_with($after, '/storage/') || str_contains($after, 'ytimg.com'))) {
                    $already++;
                    $this->line("post #{$post->id}: already-local {$after}");

                    continue;
                }

                $failed++;
                $this->line("post #{$post->id}: failed (still-remote {$after})");
            }

            return $limit === 0 || $scanned < $limit;
        });

        $this->info("Posts: scanned {$scanned}; mirrored {$mirrored}; already-local {$already}; failed {$failed}.");

        return self::SUCCESS;
    }

    private function mirrorAvatars(
        AvatarMirror $avatars,
        AvatarArchive $archive,
        PlatformAdapterManager $adapters,
        bool $fetch,
        bool $dryRun,
        int $limit,
    ): int {
        $query = SocialAccount::query()
            ->whereHas('trackedAccounts')
            ->orderBy('id');

        $trackedIds = $this->trackedIds();
        $userId = $this->option('user');

        if ($trackedIds !== []) {
            $query->whereHas('trackedAccounts', function (Builder $inner) use ($trackedIds): void {
                $inner->whereIn('id', $trackedIds);
            });
        }

        if (is_string($userId) && ctype_digit($userId)) {
            $query->whereHas('trackedAccounts', function (Builder $inner) use ($userId): void {
                $inner->where('user_id', (int) $userId);
            });
        }

        $scanned = 0;
        $mirrored = 0;
        $already = 0;
        $failed = 0;

        $query->chunkById(50, function ($accounts) use (
            $avatars,
            $archive,
            $adapters,
            $fetch,
            $dryRun,
            $limit,
            &$scanned,
            &$mirrored,
            &$already,
            &$failed,
        ): bool {
            foreach ($accounts as $social) {
                if (! $social instanceof SocialAccount) {
                    continue;
                }

                if ($limit > 0 && $scanned >= $limit) {
                    return false;
                }

                $scanned++;

                $tracker = TrackedAccount::query()
                    ->where('social_account_id', $social->id)
                    ->orderByDesc('id')
                    ->first();

                $remote = $this->avatarRemote($social, $tracker);

                if ($archive->isLocal($social->avatar) && $archive->localFileExists($social->avatar)
                    && is_string($social->avatar_source_url)
                    && ($remote === null || $social->avatar_source_url === $remote)
                    && ! $fetch) {
                    $already++;
                    $this->line("social #{$social->id}: already-local {$social->avatar}");

                    continue;
                }

                if ($fetch && ($remote === null || $this->remoteLooksDead($remote))) {
                    $refreshed = $this->refreshAvatarFromProfile($adapters, $tracker ?? $social);
                    if ($refreshed !== null) {
                        $remote = $refreshed;
                    }
                }

                if ($remote === null) {
                    $failed++;
                    $this->line("social #{$social->id}: failed (no remote)");

                    continue;
                }

                if ($dryRun) {
                    $mirrored++;
                    $this->line("social #{$social->id}: would-mirror {$remote}");

                    continue;
                }

                $before = $social->avatar;
                $avatars->apply($tracker, $social->fresh(), $remote);
                $social->refresh();

                if ($archive->isLocal($social->avatar) && $social->avatar !== $before) {
                    $mirrored++;
                    $this->line("social #{$social->id}: mirrored {$social->avatar}");

                    continue;
                }

                if ($archive->isLocal($social->avatar) && $archive->localFileExists($social->avatar)) {
                    $already++;
                    $this->line("social #{$social->id}: already-local {$social->avatar}");

                    continue;
                }

                $failed++;
                $this->line("social #{$social->id}: failed (kept ".($social->avatar ?? 'null').')');
            }

            return $limit === 0 || $scanned < $limit;
        });

        $this->info("Avatars: scanned {$scanned}; mirrored {$mirrored}; already-local {$already}; failed {$failed}.");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Post>  $query
     */
    private function constrainPosts(Builder $query): void
    {
        $trackedIds = $this->trackedIds();
        $userId = $this->option('user');

        if ($trackedIds !== []) {
            $socialIds = TrackedAccount::query()
                ->whereIn('id', $trackedIds)
                ->whereNotNull('social_account_id')
                ->pluck('social_account_id');
            $query->whereIn('social_account_id', $socialIds);
        }

        if (is_string($userId) && ctype_digit($userId)) {
            $socialIds = TrackedAccount::query()
                ->where('user_id', (int) $userId)
                ->whereNotNull('social_account_id')
                ->pluck('social_account_id');
            $query->whereIn('social_account_id', $socialIds);
        }

        $query->where(function (Builder $inner): void {
            $inner->whereNull('cover_url')
                ->orWhere('cover_url', '')
                ->orWhere(function (Builder $remote): void {
                    $remote->where('cover_url', 'like', 'http%')
                        ->where('cover_url', 'not like', '%ytimg.com%');
                });
        });
    }

    /**
     * @return list<int>
     */
    private function trackedIds(): array
    {
        $raw = $this->option('tracked');
        $values = is_array($raw) ? $raw : [];
        $ids = [];

        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    private function avatarRemote(SocialAccount $social, ?TrackedAccount $tracker): ?string
    {
        foreach ([$social->avatar_source_url, $tracker?->avatar_source_url, $social->avatar, $tracker?->avatar] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if ($candidate !== '' && (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://'))) {
                return $candidate;
            }
        }

        return null;
    }

    private function remoteLooksDead(?string $remote): bool
    {
        if ($remote === null) {
            return true;
        }

        try {
            $response = Http::timeout(8)
                ->connectTimeout(3)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->head($remote);

            return $response->status() === 403 || $response->status() === 404;
        } catch (Throwable) {
            return true;
        }
    }

    private function refreshAvatarFromProfile(
        PlatformAdapterManager $adapters,
        TrackedAccount|SocialAccount $account,
    ): ?string {
        $platform = $account->platform;
        $handle = (string) $account->handle;

        if ($handle === '') {
            return null;
        }

        try {
            $profile = $adapters->for($platform)->resolveProfile($handle);
        } catch (Throwable $e) {
            $this->line('profile refresh failed: '.$e->getMessage());

            return null;
        }

        $avatar = $profile['avatar'] ?? null;

        return is_string($avatar) && str_starts_with($avatar, 'http') ? $avatar : null;
    }
}
