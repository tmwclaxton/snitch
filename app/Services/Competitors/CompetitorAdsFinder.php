<?php

namespace App\Services\Competitors;

use App\Enums\Platform;
use App\Models\Post;
use App\Models\SocialAd;
use App\Models\TrackedAccount;
use App\Services\Firecrawl\FirecrawlClient;
use Illuminate\Support\Str;
use Throwable;

class CompetitorAdsFinder
{
    public function __construct(private FirecrawlClient $firecrawl) {}

    public function refresh(TrackedAccount $account): void
    {
        if ($account->social_account_id === null) {
            return;
        }

        $platform = $account->platform instanceof Platform
            ? $account->platform
            : Platform::tryFrom((string) $account->platform);

        if (! in_array($platform, [Platform::Instagram, Platform::Facebook], true)) {
            return;
        }

        $handle = ltrim((string) $account->handle, '@');
        $name = trim((string) ($account->display_name ?: $handle));
        $pageId = $this->pageLibraryId((int) $account->social_account_id);

        if ($handle === '' && $pageId === null) {
            return;
        }

        if ($pageId !== null) {
            $this->persistHit(
                socialAccountId: (int) $account->social_account_id,
                platform: $platform,
                url: $this->pageLibraryUrl($pageId),
                title: ($name !== '' ? $name : $handle).' Ad Library',
                body: 'Meta Ad Library for this Page.',
                raw: ['page_id' => $pageId, 'source' => 'pageAdLibrary'],
            );
        }

        if ((string) config('snitch.firecrawl.api_key') === '') {
            return;
        }

        $queries = [];

        if ($name !== '') {
            $queries[] = 'site:facebook.com/ads/library "'.$name.'"';
        }

        if ($handle !== '') {
            $queries[] = 'site:facebook.com/ads/library "'.$handle.'"';
        }

        if ($pageId !== null) {
            $queries[] = 'site:facebook.com/ads/library view_all_page_id='.$pageId;
        }

        try {
            $hits = $queries === []
                ? []
                : $this->firecrawl->searchMany($queries, ['limit' => 6]);
        } catch (Throwable) {
            return;
        }

        foreach ($hits as $hit) {
            $url = $this->libraryUrl($hit['url'] ?? '');

            if ($url === null) {
                continue;
            }

            if (! $this->hitMatchesAccount($hit, $handle, $name, $pageId)) {
                continue;
            }

            $title = trim((string) ($hit['title'] ?? ''));
            $body = trim((string) ($hit['description'] ?? ''));

            if ($title === '' || strcasecmp($title, 'Ad Library') === 0 || strcasecmp($title, 'Ads - Ad Library') === 0 || strcasecmp($title, 'See summary details - Ad Library') === 0) {
                $title = ($name !== '' ? $name : $handle).' ad';
            }

            if (str_contains(mb_strtolower($body), 'later disabled for not following')) {
                continue;
            }

            $this->persistHit(
                socialAccountId: (int) $account->social_account_id,
                platform: $platform,
                url: $url,
                title: $title,
                body: $body,
                raw: $hit,
            );
        }
    }

    /**
     * @param  array{url?: string, title?: string, description?: string}  $hit
     */
    private function hitMatchesAccount(array $hit, string $handle, string $name, ?string $pageId): bool
    {
        $url = strtolower((string) ($hit['url'] ?? ''));
        $blob = strtolower(trim(($hit['title'] ?? '').' '.($hit['description'] ?? '')));

        if ($pageId !== null && str_contains($url, 'view_all_page_id='.$pageId)) {
            return true;
        }

        if (str_contains($url, 'political_and_issue_ads')) {
            return false;
        }

        $handle = strtolower(ltrim($handle, '@'));
        $name = strtolower(trim($name));

        if ($handle !== '' && (str_contains($blob, $handle) || str_contains($url, urlencode($handle)) || str_contains($url, $handle))) {
            return true;
        }

        if ($name !== '' && str_contains($blob, $name)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function persistHit(
        int $socialAccountId,
        Platform $platform,
        string $url,
        string $title,
        string $body,
        array $raw,
    ): void {
        SocialAd::query()->updateOrCreate(
            [
                'social_account_id' => $socialAccountId,
                'url' => $url,
            ],
            [
                'platform' => $platform,
                'title' => Str::limit($title, 180, ''),
                'body' => $body !== '' ? Str::limit($body, 400, '') : null,
                'is_active' => true,
                'last_seen_at' => now(),
                'raw' => $raw,
            ],
        );
    }

    private function pageLibraryId(int $socialAccountId): ?string
    {
        $posts = Post::query()
            ->where('social_account_id', $socialAccountId)
            ->latest('posted_at')
            ->limit(25)
            ->get(['raw_payload']);

        foreach ($posts as $post) {
            $payload = is_array($post->raw_payload) ? $post->raw_payload : [];
            $library = $payload['pageAdLibrary'] ?? null;

            if (! is_array($library)) {
                continue;
            }

            $id = $library['id'] ?? null;

            if (is_string($id) && ctype_digit($id)) {
                return $id;
            }

            if (is_int($id) && $id > 0) {
                return (string) $id;
            }
        }

        return null;
    }

    private function pageLibraryUrl(string $pageId): string
    {
        return 'https://www.facebook.com/ads/library/?active_status=active&ad_type=all&country=ALL&view_all_page_id='
            .$pageId
            .'&search_type=page&media_type=all';
    }

    private function libraryUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if (! str_contains($host, 'facebook.com') && ! str_contains($host, 'fb.com')) {
            return null;
        }

        if (! str_contains($path, 'ads/library')
            && ! str_contains($query, 'id=')
            && ! str_contains($query, 'view_all_page_id=')) {
            return null;
        }

        return $url;
    }
}
