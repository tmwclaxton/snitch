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
     * Mirror a remote avatar onto the social account (and optionally the tracker).
     * Never overwrites an existing local avatar with a remote URL on failure.
     * Never throws - callers must not abort sync when mirroring fails.
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

        if ($remote === null) {
            return;
        }

        if ($this->alreadyMirrored($social, $remote) && ($tracker === null || $this->trackerMatches($tracker, $social))) {
            return;
        }

        $local = $this->archive->store((int) $social->id, $remote);

        if ($local !== null) {
            $this->writeSuccess($social, $tracker, $remote, $local);

            return;
        }

        $this->writeFailureKeepLocal($social, $tracker, $remote);
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

    private function alreadyMirrored(SocialAccount $social, string $remote): bool
    {
        return $this->archive->isLocal($social->avatar)
            && $this->archive->localFileExists($social->avatar)
            && is_string($social->avatar_source_url)
            && trim((string) $social->avatar_source_url) === $remote;
    }

    private function trackerMatches(TrackedAccount $tracker, SocialAccount $social): bool
    {
        return $this->archive->isLocal($tracker->avatar)
            && $tracker->avatar === $social->avatar
            && is_string($tracker->avatar_source_url)
            && $tracker->avatar_source_url === $social->avatar_source_url;
    }

    private function writeSuccess(SocialAccount $social, ?TrackedAccount $tracker, string $remote, string $local): void
    {
        $social->forceFill([
            'avatar' => $local,
            'avatar_source_url' => $remote,
        ])->save();

        if ($tracker !== null) {
            $tracker->forceFill([
                'avatar' => $local,
                'avatar_source_url' => $remote,
            ])->save();
        } else {
            TrackedAccount::query()
                ->where('social_account_id', $social->id)
                ->update([
                    'avatar' => $local,
                    'avatar_source_url' => $remote,
                ]);
        }
    }

    private function writeFailureKeepLocal(SocialAccount $social, ?TrackedAccount $tracker, string $remote): void
    {
        $socialFill = ['avatar_source_url' => $remote];
        $trackerFill = ['avatar_source_url' => $remote];

        if (! $this->archive->isLocal($social->avatar) || ! $this->archive->localFileExists($social->avatar)) {
            // No durable local yet - leave avatar as-is (may still be remote) but keep source.
        }

        $social->forceFill($socialFill)->save();

        if ($tracker !== null) {
            if ($this->archive->isLocal($tracker->avatar) && $this->archive->localFileExists($tracker->avatar)) {
                // keep local avatar
            }
            $tracker->forceFill($trackerFill)->save();
        }
    }
}
