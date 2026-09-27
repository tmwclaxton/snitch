const PLATFORM_LABELS: Record<string, string> = {
    instagram: 'Instagram',
    tiktok: 'TikTok',
    facebook: 'Facebook',
    linkedin: 'LinkedIn',
    youtube: 'YouTube',
};

/** Product is Instagram-only in the UI; do not advertise other networks. */
export function isInstagramPlatform(platform: string | null | undefined): boolean {
    return (platform ?? '').toLowerCase() === 'instagram';
}

export function platformLabel(platform: string): string {
    return PLATFORM_LABELS[platform] ?? platform;
}

/**
 * Badge / open-link label for the Instagram-only product.
 * Instagram stays named; legacy non-IG trackers get a neutral label.
 */
export function productPlatformLabel(platform: string | null | undefined): string {
    if (isInstagramPlatform(platform)) {
        return 'Instagram';
    }

    return 'Profile';
}

export function openOnPlatformLabel(platform: string | null | undefined): string {
    if (isInstagramPlatform(platform)) {
        return 'Open on Instagram';
    }

    return 'Open profile';
}

export function platformIconSrc(platform: string): string {
    if (!isInstagramPlatform(platform)) {
        return '/images/platforms/instagram.svg';
    }

    return `/images/platforms/${platform}.svg`;
}
