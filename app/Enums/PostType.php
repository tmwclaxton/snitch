<?php

namespace App\Enums;

enum PostType: string
{
    case Reel = 'reel';
    case Video = 'video';
    case Carousel = 'carousel';
    case Image = 'image';
    case Text = 'text';

    /**
     * @return list<self>
     */
    public static function analyzable(): array
    {
        return [self::Reel, self::Video];
    }

    /**
     * @return list<string>
     */
    public static function analyzableValues(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::analyzable(),
        );
    }

    public function isReelLike(): bool
    {
        return in_array($this, self::analyzable(), true);
    }
}
