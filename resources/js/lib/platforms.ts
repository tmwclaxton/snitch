const PLATFORM_LABELS: Record<string, string> = {
    instagram: 'Instagram',
    tiktok: 'TikTok',
    facebook: 'Facebook',
    linkedin: 'LinkedIn',
    youtube: 'YouTube',
};

export function platformLabel(platform: string): string {
    return PLATFORM_LABELS[platform] ?? platform;
}

export function platformIconSrc(platform: string): string {
    return `/images/platforms/${platform}.svg`;
}
