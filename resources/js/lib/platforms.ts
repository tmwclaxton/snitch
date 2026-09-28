const PLATFORM_LABELS: Record<string, string> = {
    instagram: 'Instagram',
    tiktok: 'TikTok',
    facebook: 'Facebook',
    linkedin: 'LinkedIn',
    youtube: 'YouTube',
};

const PLATFORM_ICONS = new Set(Object.keys(PLATFORM_LABELS));

export function isInstagramPlatform(platform: string | null | undefined): boolean {
    return (platform ?? '').toLowerCase() === 'instagram';
}

export function platformLabel(platform: string): string {
    const key = platform.toLowerCase();

    return PLATFORM_LABELS[key] ?? platform;
}

/**
 * Badge label for product UI. Always the real network name when known.
 */
export function productPlatformLabel(platform: string | null | undefined): string {
    if (!platform) {
        return 'Profile';
    }

    return platformLabel(platform);
}

export function openOnPlatformLabel(platform: string | null | undefined): string {
    const label = productPlatformLabel(platform);

    if (label === 'Profile') {
        return 'Open profile';
    }

    return `Open on ${label}`;
}

export function platformIconSrc(platform: string): string {
    const key = platform.toLowerCase();

    if (PLATFORM_ICONS.has(key)) {
        return `/images/platforms/${key}.svg`;
    }

    return '/images/platforms/instagram.svg';
}
