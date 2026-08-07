<?php

namespace App\Services\Apify;

use App\Enums\Platform;
use App\Services\Apify\Adapters\FacebookAdapter;
use App\Services\Apify\Adapters\InstagramAdapter;
use App\Services\Apify\Adapters\LinkedInAdapter;
use App\Services\Apify\Adapters\TikTokAdapter;
use App\Services\Apify\Contracts\PlatformAdapter;

class PlatformAdapterManager
{
    public function __construct(
        private InstagramAdapter $instagram,
        private TikTokAdapter $tiktok,
        private FacebookAdapter $facebook,
        private LinkedInAdapter $linkedin,
    ) {}

    public function for(Platform|string $platform): PlatformAdapter
    {
        $platform = $platform instanceof Platform ? $platform : Platform::from($platform);

        return match ($platform) {
            Platform::Instagram => $this->instagram,
            Platform::TikTok => $this->tiktok,
            Platform::Facebook => $this->facebook,
            Platform::LinkedIn => $this->linkedin,
        };
    }
}
