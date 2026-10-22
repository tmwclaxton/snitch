<?php

namespace App\Enums;

enum TrackedAccountKind: string
{
    case Competitor = 'competitor';
    case Influencer = 'influencer';
    case Own = 'own';

    public function label(): string
    {
        return match ($this) {
            self::Competitor => 'Competitor',
            self::Influencer => 'Influencer',
            self::Own => 'Own account',
        };
    }
}
