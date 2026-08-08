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
}): string[] {
    const limit = input.limit ?? 3;
    const tags: string[] = [];

    const concept = input.concept?.trim();

    if (concept) {
        tags.push(truncateLabel(concept, 28));
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

        tags.push(truncateLabel(trimmed, 22));
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

function truncateLabel(value: string, max: number): string {
    if (value.length <= max) {
        return value;
    }

    return `${value.slice(0, Math.max(0, max - 1)).trimEnd()}...`;
}
