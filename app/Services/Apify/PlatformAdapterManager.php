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

        // Cap/hard-exhaust → TikHub only when shouldUseTikHub says so. Apify-only
        // platforms (Facebook) and missing TIKHUB_API_KEY stay on Apify (cap override).
        if ($this->capGate->shouldUseTikHub($platform)) {
            return match ($platform) {
                Platform::Instagram => $this->tikhubInstagram,
                Platform::TikTok => $this->tikhubTiktok,
                Platform::LinkedIn => $this->tikhubLinkedin,
                Platform::Youtube => $this->tikhubYoutube,
                Platform::Facebook => $this->facebook,
            };
        }

        return $this->apifyAdapter($platform);
    }

    public function driverFor(Platform|string $platform): string
    {
        return $this->capGate->shouldUseTikHub($platform) ? 'tikhub' : 'apify';
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
