<?php

namespace App\Enums;

enum AnalysisTermDimension: string
{
    case HookType = 'hook_type';
    case Topic = 'topic';
    case VisualCraft = 'visual_craft';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
