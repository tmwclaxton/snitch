<?php

namespace App\Enums;

enum TrackedAccountKind: string
{
    case Competitor = 'competitor';
    case Influencer = 'influencer';

    public function label(): string
    {
        return match ($this) {
            self::Competitor => 'Competitor',
            self::Influencer => 'Influencer',
        };
    }
}
