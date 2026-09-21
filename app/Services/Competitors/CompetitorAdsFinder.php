<?php

namespace App\Services\Competitors;

use App\Enums\Platform;
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

        if ((string) config('snitch.firecrawl.api_key') === '') {
            return;
        }

        $handle = ltrim((string) $account->handle, '@');
        $name = trim((string) ($account->display_name ?: $handle));

        if ($handle === '') {
            return;
        }

        try {
            $hits = $this->firecrawl->search(
                'site:facebook.com/ads/library "'.$name.'" OR "@'.$handle.'"',
                ['limit' => 8],
            );
        } catch (Throwable) {
            return;
        }

        foreach ($hits as $hit) {
            $url = $this->libraryUrl($hit['url'] ?? '');

            if ($url === null) {
                continue;
            }

            $title = trim((string) ($hit['title'] ?? ''));
            $body = trim((string) ($hit['description'] ?? ''));

            if ($title === '') {
                $title = $name.' ad';
            }

            SocialAd::query()->updateOrCreate(
                [
                    'social_account_id' => $account->social_account_id,
                    'url' => $url,
                ],
                [
                    'platform' => $platform,
                    'title' => Str::limit($title, 180, ''),
                    'body' => $body !== '' ? Str::limit($body, 400, '') : null,
                    'is_active' => true,
                    'last_seen_at' => now(),
                    'raw' => $hit,
                ],
            );
        }
    }

    private function libraryUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if (! str_contains($host, 'facebook.com') && ! str_contains($host, 'fb.com')) {
            return null;
        }

        if (! str_contains($path, 'ads/library') && ! str_contains($url, 'id=')) {
            return null;
        }

        return $url;
    }
}
