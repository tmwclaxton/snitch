<?php

namespace App\Services\Apify\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;
use Carbon\CarbonImmutable;

class LinkedInAdapter extends AbstractPlatformAdapter
{
    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    protected function actorId(): string
    {
        return (string) config('snitch.apify.actors.linkedin');
    }

    /**
     * Company pages and personal creators use different Apimaestro actors.
     *
     * @return array{handle: string, actorId: string, input: array<string, mixed>}
     */
    public function resolveActorJob(string $handleOrUrl): array
    {
        $kind = $this->entityKind($handleOrUrl);
        $handle = $this->normalizeHandle($handleOrUrl);

        return [
            'handle' => $handle,
            'actorId' => $this->actorIdForKind($kind),
            'input' => $this->actorInputForKind($kind, $handle, 1),
        ];
    }

    public function resolveProfile(string $handleOrUrl): array
    {
        $job = $this->resolveActorJob($handleOrUrl);
        $items = $this->client->runActor($job['actorId'], $job['input']);

        return $this->profileFromActorItems($items, $job['handle']);
    }

    protected function actorInput(string $handle, int $limit, ?CarbonImmutable $since = null): array
    {
        // Sync / listRecentPosts defaults to company pages (brand competitors).
        return $this->actorInputForKind('company', $handle, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function actorInputForKind(string $kind, string $handle, int $limit): array
    {
        $limit = max(1, min(100, $limit));

        if ($kind === 'in') {
            return [
                'username' => $handle,
                'limit' => $limit,
                'total_posts' => $limit,
            ];
        }

        return [
            'company_name' => $handle,
            'limit' => $limit,
            'sort' => 'recent',
        ];
    }

    private function actorIdForKind(string $kind): string
    {
        if ($kind === 'in') {
            return (string) config(
                'snitch.apify.actors.linkedin_profile',
                'apimaestro/linkedin-profile-posts',
            );
        }

        return (string) config(
            'snitch.apify.actors.linkedin',
            'apimaestro/linkedin-company-posts',
        );
    }

    /**
     * @return 'company'|'in'
     */
    private function entityKind(string $handleOrUrl): string
    {
        $value = trim($handleOrUrl);

        if (str_contains($value, 'linkedin.com')) {
            $path = (string) parse_url(
                str_starts_with($value, 'http') ? $value : 'https://'.$value,
                PHP_URL_PATH,
            );
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));

            if (($segments[0] ?? null) === 'in') {
                return 'in';
            }
        }

        return 'company';
    }

    protected function mapProfile(array $item, string $handle): ?array
    {
        $author = is_array($item['author'] ?? null) ? $item['author'] : [];
        $sourceCompany = isset($item['source_company']) && is_string($item['source_company'])
            ? trim($item['source_company'])
            : '';
        $authorUsername = isset($author['username']) && is_string($author['username'])
            ? ltrim(trim($author['username']), '@')
            : '';

        $resolvedHandle = $sourceCompany !== ''
            ? $sourceCompany
            : ($authorUsername !== '' ? $authorUsername : $handle);

        $externalId = null;

        if (isset($author['id']) && filled($author['id'])) {
            $externalId = (string) $author['id'];
        } elseif ($sourceCompany !== '') {
            $externalId = $sourceCompany;
        } elseif ($authorUsername !== '') {
            $externalId = $authorUsername;
        } elseif (isset($item['full_urn']) && is_string($item['full_urn']) && $item['full_urn'] !== '') {
            // Any successful post payload proves the entity resolves.
            $externalId = $resolvedHandle;
        } elseif ($author !== [] || isset($item['activity_urn']) || isset($item['urn'])) {
            $externalId = $resolvedHandle;
        }

        if ($externalId === null) {
            return null;
        }

        $displayName = null;

        if (isset($author['name']) && is_string($author['name']) && trim($author['name']) !== '') {
            $displayName = trim($author['name']);
        } elseif (isset($author['first_name']) || isset($author['last_name'])) {
            $displayName = trim(implode(' ', array_filter([
                is_string($author['first_name'] ?? null) ? $author['first_name'] : null,
                is_string($author['last_name'] ?? null) ? $author['last_name'] : null,
            ])));
        }

        $avatar = null;

        foreach (['logo_url', 'profile_picture', 'image', 'avatar'] as $avatarKey) {
            if (isset($author[$avatarKey]) && is_string($author[$avatarKey]) && $author[$avatarKey] !== '') {
                $avatar = $author[$avatarKey];
                break;
            }
        }

        $kind = $sourceCompany !== '' || str_contains((string) ($author['company_url'] ?? ''), '/company/')
            ? 'company'
            : (str_contains((string) ($author['profile_url'] ?? ''), '/in/') || $authorUsername !== '' ? 'in' : 'company');

        return [
            'platform' => $this->platform(),
            'handle' => $resolvedHandle,
            'url' => $kind === 'in'
                ? "https://linkedin.com/in/{$resolvedHandle}"
                : $this->profileUrl($resolvedHandle),
            'external_id' => $externalId,
            'avatar' => $avatar,
            'display_name' => $displayName !== null && $displayName !== '' ? $displayName : $resolvedHandle,
        ];
    }

    protected function mapPost(array $item, string $handle): ?array
    {
        $url = (string) ($item['post_url'] ?? $item['url'] ?? $item['postUrl'] ?? $item['shareUrl'] ?? '');

        if ($url === '') {
            return null;
        }

        $media = is_array($item['media'] ?? null) ? $item['media'] : [];
        $mediaUrl = $this->firstVideoUrl(
            $item['video']['url'] ?? null,
            $item['videoUrl'] ?? null,
            $item['mediaUrl'] ?? null,
            $media['url'] ?? null,
            is_array($media['items'][0] ?? null) ? ($media['items'][0]['url'] ?? null) : null,
        );

        // LinkedIn often returns image carousels; only import real video media.
        $mediaType = strtolower((string) ($media['type'] ?? $item['type'] ?? ''));

        if ($mediaUrl === null || str_contains($mediaType, 'image')) {
            if (! $this->looksLikeVideoUrl($mediaUrl)) {
                return null;
            }
        }

        // Reject LinkedIn page URLs - they are not analyzable video files.
        if ($mediaUrl === null || str_contains(strtolower($mediaUrl), 'linkedin.com/')) {
            return null;
        }

        $type = $this->inferPostType((string) ($item['post_type'] ?? $item['type'] ?? 'video'), $mediaUrl);

        if (! $this->isImportableReelType($type, $mediaUrl)) {
            return null;
        }

        $stats = is_array($item['stats'] ?? null) ? $item['stats'] : [];
        $postedAt = $item['posted_at'] ?? $item['postedAt'] ?? $item['publishedAt'] ?? $item['timestamp'] ?? null;

        if (is_array($postedAt)) {
            $postedAt = $postedAt['date'] ?? $postedAt['timestamp'] ?? null;
        }

        $externalId = null;

        if (isset($item['activity_urn'])) {
            $externalId = (string) $item['activity_urn'];
        } elseif (is_array($item['urn'] ?? null) && isset($item['urn']['activity_urn'])) {
            $externalId = (string) $item['urn']['activity_urn'];
        } elseif (isset($item['full_urn'])) {
            $externalId = (string) $item['full_urn'];
        } elseif (isset($item['urn']) && is_string($item['urn'])) {
            $externalId = $item['urn'];
        } elseif (isset($item['id'])) {
            $externalId = (string) $item['id'];
        }

        return [
            'external_id' => $externalId,
            'url' => $url,
            'posted_at' => $this->normalizeDate($postedAt),
            'type' => $type === PostType::Image->value ? PostType::Video->value : $type,
            'caption' => isset($item['text']) ? (string) $item['text'] : (isset($item['commentary']) ? (string) $item['commentary'] : null),
            'media_url' => $mediaUrl,
            'metrics' => $this->metrics(
                $item['views'] ?? $stats['views'] ?? 0,
                $item['likes'] ?? $stats['like'] ?? $stats['total_reactions'] ?? $item['numLikes'] ?? 0,
                $item['comments'] ?? $stats['comments'] ?? $item['numComments'] ?? 0,
                $item['shares'] ?? $stats['reposts'] ?? $item['numShares'] ?? 0,
            ),
            'raw_payload' => $item,
        ];
    }
}
