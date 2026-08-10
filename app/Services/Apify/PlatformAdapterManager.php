<?php

namespace App\Services\Apify;

use App\Enums\Platform;
use App\Services\Apify\Adapters\FacebookAdapter;
use App\Services\Apify\Adapters\InstagramAdapter as ApifyInstagramAdapter;
use App\Services\Apify\Adapters\LinkedInAdapter as ApifyLinkedInAdapter;
use App\Services\Apify\Adapters\TikTokAdapter as ApifyTikTokAdapter;
use App\Services\Apify\Adapters\YoutubeAdapter as ApifyYoutubeAdapter;
use App\Services\Apify\Contracts\PlatformAdapter;
use App\Services\Scraping\ApifyMonthlyCapGate;
use App\Services\TikHub\Adapters\InstagramAdapter as TikHubInstagramAdapter;
use App\Services\TikHub\Adapters\LinkedInAdapter as TikHubLinkedInAdapter;
use App\Services\TikHub\Adapters\TikTokAdapter as TikHubTikTokAdapter;
use App\Services\TikHub\Adapters\YoutubeAdapter as TikHubYoutubeAdapter;
use RuntimeException;

class PlatformAdapterManager
{
    public function __construct(
        private ApifyMonthlyCapGate $capGate,
        private ApifyInstagramAdapter $apifyInstagram,
        private ApifyTikTokAdapter $apifyTiktok,
        private FacebookAdapter $facebook,
        private ApifyLinkedInAdapter $apifyLinkedin,
        private ApifyYoutubeAdapter $apifyYoutube,
        private TikHubInstagramAdapter $tikhubInstagram,
        private TikHubTikTokAdapter $tikhubTiktok,
        private TikHubLinkedInAdapter $tikhubLinkedin,
        private TikHubYoutubeAdapter $tikhubYoutube,
    ) {}

    public function for(Platform|string $platform): PlatformAdapter
    {
        $platform = $platform instanceof Platform ? $platform : Platform::from($platform);

        if ($this->capGate->isApifyExhausted() && $this->capGate->tikHubConfigured()) {
            return match ($platform) {
                Platform::Instagram => $this->tikhubInstagram,
                Platform::TikTok => $this->tikhubTiktok,
                Platform::LinkedIn => $this->tikhubLinkedin,
                Platform::Youtube => $this->tikhubYoutube,
                Platform::Facebook => throw new RuntimeException(
                    'Facebook sync is unavailable while Apify monthly usage is exhausted (TikHub has no Facebook coverage).',
                ),
            };
        }

        return match ($platform) {
            Platform::Instagram => $this->apifyInstagram,
            Platform::TikTok => $this->apifyTiktok,
            Platform::Facebook => $this->facebook,
            Platform::LinkedIn => $this->apifyLinkedin,
            Platform::Youtube => $this->apifyYoutube,
        };
    }

    public function driverFor(Platform|string $platform): string
    {
        $platform = $platform instanceof Platform ? $platform : Platform::from($platform);

        if ($this->capGate->isApifyExhausted() && $this->capGate->tikHubConfigured() && $platform !== Platform::Facebook) {
            return 'tikhub';
        }

        return 'apify';
    }

    public function apifyAdapter(Platform|string $platform): PlatformAdapter
    {
        $platform = $platform instanceof Platform ? $platform : Platform::from($platform);

        return match ($platform) {
            Platform::Instagram => $this->apifyInstagram,
            Platform::TikTok => $this->apifyTiktok,
            Platform::Facebook => $this->facebook,
            Platform::LinkedIn => $this->apifyLinkedin,
            Platform::Youtube => $this->apifyYoutube,
        };
    }

    public function tikHubAdapter(Platform|string $platform): ?PlatformAdapter
    {
        $platform = $platform instanceof Platform ? $platform : Platform::from($platform);

        return match ($platform) {
            Platform::Instagram => $this->tikhubInstagram,
            Platform::TikTok => $this->tikhubTiktok,
            Platform::LinkedIn => $this->tikhubLinkedin,
            Platform::Youtube => $this->tikhubYoutube,
            Platform::Facebook => null,
        };
    }
}
