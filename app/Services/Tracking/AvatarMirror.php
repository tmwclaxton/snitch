<?php

namespace App\Services\Tracking;

use App\Models\SocialAccount;
use App\Models\TrackedAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

class AvatarMirror
{
    public function __construct(private AvatarArchive $archive = new AvatarArchive) {}

    /**
     * Mirror a remote avatar onto the social account and every tracker that
     * shares it. Never overwrites an existing local avatar with a remote URL
     * on failure. Never throws - callers must not abort sync when mirroring fails.
     */
    public function apply(?TrackedAccount $tracker, ?SocialAccount $social, ?string $remoteUrl): void
    {
        try {
            $this->applyOrThrow($tracker, $social, $remoteUrl);
        } catch (Throwable $e) {
            Log::warning('Avatar mirror failed', [
                'tracked_account_id' => $tracker?->id,
                'social_account_id' => $social?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function applyOrThrow(?TrackedAccount $tracker, ?SocialAccount $social, ?string $remoteUrl): void
    {
        $social ??= $tracker?->socialAccount;

        if ($social === null && $tracker?->social_account_id !== null) {
            $social = SocialAccount::query()->find($tracker->social_account_id);
        }

        if ($social === null) {
            return;
        }

        $remote = $this->resolveRemote($tracker, $social, $remoteUrl);

        // Social already mirrored: copy the local path to every tracker with no
        // network call unless the remote source URL has changed.
        if ($this->archive->isLocal($social->avatar) && $this->archive->localFileExists($social->avatar)) {
            $source = is_string($social->avatar_source_url) ? trim($social->avatar_source_url) : '';

            if ($remote === null || $source === '' || $source === $remote) {
                $this->propagateToAllTrackers(
                    $social,
                    (string) $social->avatar,
                    $source !== '' ? $source : $remote,
                );

                return;
            }
        }

        if ($remote === null) {
            return;
        }

        $local = $this->archive->store((int) $social->id, $remote);

        if ($local !== null) {
            $this->writeSuccess($social, $remote, $local);

            return;
        }

        $this->writeFailureKeepLocal($social, $remote);
    }

    private function resolveRemote(?TrackedAccount $tracker, SocialAccount $social, ?string $remoteUrl): ?string
    {
        foreach ([$remoteUrl, $social->avatar_source_url, $tracker?->avatar_source_url, $social->avatar, $tracker?->avatar] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if ($candidate === '' || $this->archive->isLocal($candidate)) {
                continue;
            }

            if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
                return $candidate;
            }
        }

        return null;
    }

    private function writeSuccess(SocialAccount $social, string $remote, string $local): void
    {
        $social->forceFill([
            'avatar' => $local,
            'avatar_source_url' => $remote,
        ])->save();

        $this->propagateToAllTrackers($social, $local, $remote);
    }

    /**
     * Copy a durable local avatar onto every tracked_accounts row for this social.
     */
    public function propagateToAllTrackers(SocialAccount $social, string $local, ?string $sourceUrl): void
    {
        $fill = ['avatar' => $local];

        if (is_string($sourceUrl) && $sourceUrl !== '') {
            $fill['avatar_source_url'] = $sourceUrl;
        }

        TrackedAccount::query()
            ->where('social_account_id', $social->id)
            ->update($fill);
    }

    private function writeFailureKeepLocal(SocialAccount $social, string $remote): void
    {
        $social->forceFill([
            'avatar_source_url' => $remote,
        ])->save();

        // Keep each tracker's local avatar if it has one; only refresh the source URL.
        TrackedAccount::query()
            ->where('social_account_id', $social->id)
            ->update([
                'avatar_source_url' => $remote,
            ]);
    }
}
