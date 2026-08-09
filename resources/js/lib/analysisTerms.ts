import {
    Aperture,
    BadgeCheck,
    BookOpen,
    Briefcase,
    Clapperboard,
    Columns2,
    Cpu,
    FileText,
    HandCoins,
    Hash,
    HelpCircle,
    Leaf,
    Lightbulb,
    ListOrdered,
    Megaphone,
    Monitor,
    Palette,
    Repeat,
    Scale,
    Scissors,
    Search,
    Share2,
    Smile,
    Sparkles,
    Tag,
    Timer,
    TrendingUp,
    Type,
    Users,
    Video,
    Volume2,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';
import { index as exploreIndex } from '@/actions/App/Http/Controllers/ExploreController';

export type AnalysisTermDimension =
    | 'hook_type'
    | 'topic'
    | 'visual_craft'
    | 'custom'
    | 'concept'
    | 'search'
    | string;

export type AnalysisTermIconInput = {
    dimension?: AnalysisTermDimension | null;
    section?: string | null;
    slug?: string | null;
    label?: string | null;
};

const DIMENSION_ICONS: Record<string, Component> = {
    hook_type: Zap,
    topic: Tag,
    visual_craft: Aperture,
    custom: Hash,
    concept: Lightbulb,
    search: Search,
};

const SECTION_ICONS: Record<string, Component> = {
    'claims & takes': Megaphone,
    'curiosity & questions': HelpCircle,
    'lists & promises': ListOrdered,
    'story & confession': BookOpen,
    'social formats': Share2,
    'visual & sound opens': Volume2,
    'proof & authority': BadgeCheck,
    'sell & urgency': Timer,
    'comedy & character': Smile,
    'grants & nonprofit': HandCoins,
    'marketing & growth': TrendingUp,
    'product & business': Briefcase,
    'ops & tech': Cpu,
    'career & people': Users,
    'money & legal': Scale,
    'craft & brand': Palette,
    'lifestyle & causes': Leaf,
    'talking head & cam': Video,
    'layouts & splits': Columns2,
    'text & captions': Type,
    'cuts & motion': Scissors,
    'screen & ui': Monitor,
    'proof & docs': FileText,
    'b-roll & product': Clapperboard,
    'grade & effects': Sparkles,
    'endings & loops': Repeat,
};

/**
 * Pick a Lucide icon for an analysis catalogue term / glance tag.
 * Prefer section, then dimension defaults.
 */
export function analysisTermIcon(input: AnalysisTermIconInput): Component {
    const section = input.section?.trim().toLowerCase();

    if (section && SECTION_ICONS[section]) {
        return SECTION_ICONS[section];
    }

    const dimension = input.dimension?.trim().toLowerCase();

    if (dimension && DIMENSION_ICONS[dimension]) {
        return DIMENSION_ICONS[dimension];
    }

    return Tag;
}

export function analysisDimensionIcon(dimension: AnalysisTermDimension | null | undefined): Component {
    return analysisTermIcon({ dimension });
}

/**
 * Explore URL with the matching catalogue filter, custom tag, or free-text `q` preselected.
 * Explore accepts `hook_types` / `topics` / `visual_crafts` / `custom_tag` / `q` query params.
 */
export function exploreHrefForTerm(input: {
    dimension?: AnalysisTermDimension | null;
    slug?: string | null;
    label?: string | null;
    searchValue?: string | null;
}): string | null {
    const dimension = input.dimension?.trim().toLowerCase() ?? '';
    const slug = input.slug?.trim().toLowerCase() ?? '';

    if (dimension === 'hook_type' && slug !== '') {
        return exploreIndex.url({ query: { hook_types: slug } });
    }

    if (dimension === 'topic' && slug !== '') {
        return exploreIndex.url({ query: { topics: slug } });
    }

    if (dimension === 'visual_craft' && slug !== '') {
        return exploreIndex.url({ query: { visual_crafts: slug } });
    }

    if (dimension === 'custom') {
        const customTag = (input.searchValue ?? input.slug ?? input.label ?? '').trim();

        if (customTag === '') {
            return null;
        }

        return exploreIndex.url({ query: { custom_tag: customTag } });
    }

    const q = (input.searchValue ?? input.label ?? input.slug ?? '').trim();

    if (q === '') {
        return null;
    }

    return exploreIndex.url({ query: { q } });
}
