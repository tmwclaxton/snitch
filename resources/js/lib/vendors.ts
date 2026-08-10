export type SpendVendorKey = 'apify' | 'nanogpt' | 'firecrawl' | 'tikhub';

const VENDOR_LABELS: Record<string, string> = {
    apify: 'Apify',
    nanogpt: 'NanoGPT',
    firecrawl: 'Firecrawl',
    tikhub: 'TikHub',
    bonus: 'Bonus',
    topup: 'Top up',
};

/**
 * Vendor mark filenames under /images/vendors/.
 * Third-party marks are official brand assets (see commit notes for URLs).
 * bonus/topup are internal ledger labels, not external brands.
 */
const VENDOR_ICON_FILES: Record<string, string> = {
    apify: 'apify.svg',
    nanogpt: 'nanogpt.svg',
    firecrawl: 'firecrawl.svg',
    tikhub: 'tikhub.png',
    bonus: 'bonus.svg',
    topup: 'topup.svg',
};

/** SVG fill utilities for stacked spend stipple segments (must stay unique per vendor). */
export const VENDOR_CHART_FILL: Record<SpendVendorKey, string> = {
    apify: 'fill-snitch-ink/75',
    nanogpt: 'fill-snitch-stipple-spot',
    firecrawl: 'fill-snitch-teal',
    tikhub: 'fill-snitch-ink/40',
};

/** Matching legend / chip background utilities (unique keys - no duplicate object keys). */
export const VENDOR_CHART_SWATCH: Record<SpendVendorKey, string> = {
    apify: 'bg-snitch-ink/75',
    nanogpt: 'bg-snitch-stipple-spot',
    firecrawl: 'bg-snitch-teal',
    tikhub: 'bg-snitch-ink/40',
};

export const VENDOR_ACCENT_BORDER: Record<SpendVendorKey, string> = {
    apify: 'border-l-snitch-ink/70',
    nanogpt: 'border-l-snitch-stipple-spot',
    firecrawl: 'border-l-snitch-teal',
    tikhub: 'border-l-snitch-ink/40',
};

export const SPEND_VENDORS: SpendVendorKey[] = [
    'apify',
    'nanogpt',
    'firecrawl',
    'tikhub',
];

export function vendorLabel(vendor: string): string {
    return VENDOR_LABELS[vendor] ?? vendor;
}

export function vendorIconSrc(vendor: string): string {
    const file = VENDOR_ICON_FILES[vendor] ?? `${vendor}.svg`;

    return `/images/vendors/${file}`;
}

export function isSpendVendor(vendor: string): vendor is SpendVendorKey {
    return SPEND_VENDORS.includes(vendor as SpendVendorKey);
}
