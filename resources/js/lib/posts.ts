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
    const limit = input.limit ?? 3;
    const tags: string[] = [];
    const conceptMax =
        input.maxLength === undefined ? 28 : input.maxLength;
    const topicMax =
        input.maxLength === undefined ? 22 : input.maxLength;

    const concept = input.concept?.trim();

    if (concept) {
        tags.push(formatTagLabel(concept, conceptMax));
    }

    for (const topic of input.topics ?? []) {
        if (tags.length >= limit) {
            break;
        }

        const trimmed = topic.trim();

        if (!trimmed) {
            continue;
        }

        if (tags.some((tag) => tag.toLowerCase() === trimmed.toLowerCase())) {
            continue;
        }

        tags.push(formatTagLabel(trimmed, topicMax));
    }

    return tags.slice(0, limit);
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
