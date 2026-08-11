export type SpendVendorKey = 'apify' | 'nanogpt' | 'firecrawl' | 'tikhub' | 'snitch';

const VENDOR_LABELS: Record<string, string> = {
    apify: 'Apify',
    nanogpt: 'NanoGPT',
    firecrawl: 'Firecrawl',
    tikhub: 'TikHub',
    snitch: 'Snitch',
    bonus: 'Bonus',
    topup: 'Top up',
};

/**
 * Vendor mark paths. Relative filenames resolve under /images/vendors/.
 * Absolute paths (Snitch brand mark) are used as-is so billing matches favicon / AppLogoIcon.
 * Third-party marks are official brand assets (see commit notes for URLs).
 * bonus/topup are internal ledger labels, not external brands.
 */
const VENDOR_ICON_FILES: Record<string, string> = {
    apify: 'apify.svg',
    nanogpt: 'nanogpt.svg',
    firecrawl: 'firecrawl.svg',
    tikhub: 'tikhub.png',
    snitch: '/images/brand/mascot-mark.png',
    bonus: 'bonus.svg',
    topup: 'topup.svg',
};

/** SVG fill utilities for stacked spend stipple segments (must stay unique per vendor). */
export const VENDOR_CHART_FILL: Record<SpendVendorKey, string> = {
    apify: 'fill-snitch-ink/75',
    nanogpt: 'fill-snitch-ink/55',
    firecrawl: 'fill-snitch-teal',
    tikhub: 'fill-snitch-ink/40',
    snitch: 'fill-snitch-stipple-spot',
};

export const VENDOR_ACCENT_BORDER: Record<SpendVendorKey, string> = {
    apify: 'border-l-snitch-ink/70',
    nanogpt: 'border-l-snitch-ink/55',
    firecrawl: 'border-l-snitch-teal',
    tikhub: 'border-l-snitch-ink/40',
    snitch: 'border-l-snitch-stipple-spot',
};

export const SPEND_VENDORS: SpendVendorKey[] = [
    'apify',
    'nanogpt',
    'firecrawl',
    'tikhub',
    'snitch',
];

export function vendorLabel(vendor: string): string {
    return VENDOR_LABELS[vendor] ?? vendor;
}

export function vendorIconSrc(vendor: string): string {
    const file = VENDOR_ICON_FILES[vendor] ?? `${vendor}.svg`;

    if (file.startsWith('/')) {
        return file;
    }

    return `/images/vendors/${file}`;
}

export function isSpendVendor(vendor: string): vendor is SpendVendorKey {
    return SPEND_VENDORS.includes(vendor as SpendVendorKey);
}
