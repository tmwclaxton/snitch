export function postTypeLabel(type: string): string {
    switch (type) {
        case 'reel':
            return 'Reel';
        case 'video':
            return 'Video';
        case 'carousel':
            return 'Carousel';
        case 'image':
            return 'Image';
        case 'text':
            return 'Text';
        default:
            return type.charAt(0).toUpperCase() + type.slice(1);
    }
}

/**
 * Primary label for a feed frame / post dossier.
 * Prefer the creator caption, then analysis hook / concept, then type.
 */
export function postPrimaryTitle(input: {
    caption?: string | null;
    hook?: string | null;
    concept?: string | null;
    type?: string | null;
    maxLength?: number;
}): string {
    const maxLength = input.maxLength ?? 96;
    const caption = firstCaptionLine(input.caption);

    if (caption) {
        return truncateLabel(caption, maxLength);
    }

    const hook = input.hook?.trim();

    if (hook) {
        return truncateLabel(hook, maxLength);
    }

    const concept = input.concept?.trim();

    if (concept) {
        return truncateLabel(concept, maxLength);
    }

    if (input.type) {
        return postTypeLabel(input.type);
    }

    return 'Post';
}

export type GlanceTermChip = {
    key: string;
    label: string;
    dimension: string;
    section?: string | null;
    slug?: string | null;
};

export type AnalysisTermLabel = {
    dimension: string;
    slug: string;
    label: string;
    section?: string | null;
};

export function glanceTags(input: {
    concept?: string | null;
    topics?: string[] | null;
    limit?: number;
    /**
     * Character cap per tag.
     * Omit for compact feed glance defaults (concept 28 / topic 22).
     * Pass null to keep full labels (winners / roomier surfaces).
     */
    maxLength?: number | null;
}): string[] {
    return glanceTermChips(input).map((chip) => chip.label);
}

/**
 * Structured glance chips with dimension metadata for icons.
 * Prefer catalogue term_labels when present.
 */
export function glanceTermChips(input: {
    concept?: string | null;
    topics?: string[] | null;
    termLabels?: AnalysisTermLabel[] | null;
    customTags?: string[] | null;
    limit?: number;
    /**
     * Character cap per tag.
     * Omit for compact feed glance defaults (concept 28 / topic 22).
     * Pass null to keep full labels (winners / roomier surfaces).
     */
    maxLength?: number | null;
}): GlanceTermChip[] {
    const limit = input.limit ?? 3;
    const chips: GlanceTermChip[] = [];
    const conceptMax =
        input.maxLength === undefined ? 28 : input.maxLength;
    const topicMax =
        input.maxLength === undefined ? 22 : input.maxLength;
    const seen = new Set<string>();

    const pushChip = (chip: GlanceTermChip): void => {
        if (chips.length >= limit) {
            return;
        }

        const key = chip.label.toLowerCase();

        if (!key || seen.has(key)) {
            return;
        }

        seen.add(key);
        chips.push(chip);
    };

    const termLabels = input.termLabels ?? [];

    if (termLabels.length > 0) {
        const dimensionOrder = ['hook_type', 'topic', 'visual_craft'];
        const sorted = [...termLabels].sort((a, b) => {
            const ai = dimensionOrder.indexOf(a.dimension);
            const bi = dimensionOrder.indexOf(b.dimension);

            return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
        });

        for (const term of sorted) {
            pushChip({
                key: `${term.dimension}:${term.slug}`,
                label: formatTagLabel(term.label, topicMax),
                dimension: term.dimension,
                section: term.section ?? null,
                slug: term.slug,
            });
        }

        for (const custom of input.customTags ?? []) {
            const trimmed = custom.trim();

            if (!trimmed) {
                continue;
            }

            pushChip({
                key: `custom:${trimmed.toLowerCase()}`,
                label: formatTagLabel(trimmed, topicMax),
                dimension: 'custom',
            });
        }

        return chips;
    }

    const concept = input.concept?.trim();

    if (concept) {
        pushChip({
            key: `concept:${concept.toLowerCase()}`,
            label: formatTagLabel(concept, conceptMax),
            dimension: 'concept',
        });
    }

    for (const topic of input.topics ?? []) {
        const trimmed = topic.trim();

        if (!trimmed) {
            continue;
        }

        pushChip({
            key: `topic:${trimmed.toLowerCase()}`,
            label: formatTagLabel(trimmed, topicMax),
            dimension: 'topic',
        });
    }

    for (const custom of input.customTags ?? []) {
        const trimmed = custom.trim();

        if (!trimmed) {
            continue;
        }

        pushChip({
            key: `custom:${trimmed.toLowerCase()}`,
            label: formatTagLabel(trimmed, topicMax),
            dimension: 'custom',
        });
    }

    return chips;
}

function firstCaptionLine(caption?: string | null): string | null {
    if (!caption) {
        return null;
    }

    for (const line of caption.split(/\r?\n/)) {
        const trimmed = line.trim();

        if (trimmed) {
            return trimmed;
        }
    }

    return null;
}

function formatTagLabel(value: string, max: number | null): string {
    if (max === null) {
        return value;
    }

    return truncateLabel(value, max);
}

function truncateLabel(value: string, max: number): string {
    if (value.length <= max) {
        return value;
    }

    return `${value.slice(0, Math.max(0, max - 1)).trimEnd()}...`;
}
