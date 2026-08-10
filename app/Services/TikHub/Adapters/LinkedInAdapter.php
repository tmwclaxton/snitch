<?php

namespace App\Services\TikHub\Adapters;

use App\Enums\Platform;
use App\Enums\PostType;
use Carbon\CarbonImmutable;

class LinkedInAdapter extends AbstractTikHubAdapter
{
    public function platform(): Platform
    {
        return Platform::LinkedIn;
    }

    public function resolveProfile(string $handleOrUrl): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);
        $kind = $this->entityKind($handleOrUrl);
        $url = $this->entityUrl($handle, $kind);

        if ($kind === 'in') {
            $payload = $this->client->get($this->endpoint('profile_posts'), [
                'url' => $url,
            ], 'linkedin');
        } else {
            try {
                $payload = $this->client->get($this->endpoint('company_info'), [
                    'url' => $url,
                ], 'linkedin');
            } catch (\Throwable) {
                $payload = $this->client->get($this->endpoint('company_posts'), [
                    'url' => $url,
                ], 'linkedin');
            }
        }

        $item = $this->extractObject($payload, ['company', 'profile', 'data', 'author']);

        return $this->profileFromItems([$item !== [] ? $item : $payload], $handle);
    }

    public function listRecentPosts(string $handleOrUrl, int $limit = 12, ?CarbonImmutable $since = null): array
    {
        $handle = $this->normalizeHandle($handleOrUrl);
        $kind = $this->entityKind($handleOrUrl);
        $url = $this->entityUrl($handle, $kind);

        if ($kind === 'in') {
            $payload = $this->client->get($this->endpoint('profile_posts'), [
                'url' => $url,
            ], 'linkedin');
        } else {
            $payload = $this->client->get($this->endpoint('company_posts'), [
                'url' => $url,
            ], 'linkedin');
        }

        $items = $this->extractList($payload, ['posts', 'data.posts', 'items', 'elements']);

        return $this->postsFromItems($items, $handle, $limit, $since);
    }

    private function entityUrl(string $handle, string $kind): string
    {
        return $kind === 'in'
            ? "https://www.linkedin.com/in/{$handle}"
            : "https://www.linkedin.com/company/{$handle}";
    }

    protected function mapProfile(array $item, string $handle): ?array
    {
        $resolved = ltrim((string) ($item['vanityName'] ?? $item['universalName'] ?? $item['username'] ?? $item['name'] ?? $handle), '@');
        $externalId = $item['companyId'] ?? $item['entityUrn'] ?? $item['id'] ?? null;

        return [
            'platform' => $this->platform(),
            'handle' => $resolved !== '' ? $resolved : $handle,
            'url' => $this->profileUrl($resolved !== '' ? $resolved : $handle),
            'external_id' => $externalId !== null ? (string) $externalId : null,
            'avatar' => $item['logoUrl'] ?? $item['profilePicture'] ?? $item['avatar'] ?? null,
            'display_name' => (string) ($item['name'] ?? $item['title'] ?? $resolved),
        ];
    }

    protected function mapPost(array $item, string $handle): ?array
    {
        $url = (string) ($item['postUrl'] ?? $item['url'] ?? $item['shareUrl'] ?? '');

        if ($url === '') {
            return null;
        }

        $mediaUrl = $this->firstVideoUrl(
            $item['videoUrl'] ?? null,
            $item['mediaUrl'] ?? null,
            data_get($item, 'content.video.url'),
            data_get($item, 'media.video.url'),
        );

        if ($mediaUrl === null) {
            return null;
        }

        $type = $this->inferPostType('video', $mediaUrl);

        if (! $this->isImportableReelType($type, $mediaUrl)) {
            return null;
        }

        return [
            'external_id' => isset($item['urn']) ? (string) $item['urn'] : (isset($item['id']) ? (string) $item['id'] : null),
            'url' => $url,
            'posted_at' => $this->normalizeDate($item['postedAt'] ?? $item['createdAt'] ?? $item['publishedAt'] ?? null),
            'type' => PostType::Reel->value,
            'caption' => isset($item['text']) ? (string) $item['text'] : (isset($item['commentary']) ? (string) $item['commentary'] : null),
            'media_url' => $mediaUrl,
            'metrics' => $this->metrics(
                $item['views'] ?? $item['numViews'] ?? 0,
                $item['likes'] ?? $item['numLikes'] ?? 0,
                $item['comments'] ?? $item['numComments'] ?? 0,
                $item['shares'] ?? $item['numShares'] ?? 0,
            ),
            'raw_payload' => $item,
        ];
    }

    protected function profileUrl(string $handle): string
    {
        return "https://linkedin.com/company/{$handle}";
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

            if (str_contains($path, '/in/')) {
                return 'in';
            }
        }

        return 'company';
    }
}
