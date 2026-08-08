<?php

namespace App\Enums;

enum Platform: string
{
    case TikTok = 'tiktok';
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case LinkedIn = 'linkedin';
    case Youtube = 'youtube';

    public function label(): string
    {
        return match ($this) {
            self::TikTok => 'TikTok',
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::LinkedIn => 'LinkedIn',
            self::Youtube => 'YouTube',
        };
    }
}
