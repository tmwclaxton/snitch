/**
 * Read live snitch CSS tokens so charts / canvas colours follow appearance.
 */
export function snitchCssVar(name: string, fallback: string): string {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return fallback;
    }

    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(name)
        .trim();

    return value || fallback;
}

export function snitchIsDark(): boolean {
    if (typeof document === 'undefined') {
        return false;
    }

    return document.documentElement.classList.contains('dark');
}

export function snitchInk(fallback = '#1c1b1a'): string {
    return snitchCssVar('--snitch-ink', fallback);
}

export function snitchPaper(fallback = '#efe6d8'): string {
    return snitchCssVar('--snitch-paper', fallback);
}

export function snitchFog(fallback = '#e4d9c8'): string {
    return snitchCssVar('--snitch-fog', fallback);
}

export function snitchSpot(fallback = '#f0c400'): string {
    return snitchCssVar('--snitch-spot', fallback);
}

/** "You" series: charcoal on light paper, mustard on night. */
export function snitchYouSeries(): string {
    return snitchIsDark() ? '#f0c400' : snitchInk('#1c1b1a');
}

/** Distinct rival hues that stay readable on both shells. */
export const SNITCH_RIVAL_COLOURS_LIGHT = [
    '#3A5F6B',
    '#E5341D',
    '#5B7C99',
    '#8B5A2B',
    '#2F6F4E',
    '#6B4C7A',
    '#B45309',
    '#0F766E',
] as const;

export const SNITCH_RIVAL_COLOURS_DARK = [
    '#E07A5F',
    '#4FC3F7',
    '#7CFFB2',
    '#FF8A3D',
    '#C084FC',
    '#67E8F9',
    '#FDA4AF',
    '#A3E635',
] as const;

export function snitchRivalColours(): readonly string[] {
    return snitchIsDark() ? SNITCH_RIVAL_COLOURS_DARK : SNITCH_RIVAL_COLOURS_LIGHT;
}

export function snitchNormalizeHandle(value: string | null | undefined): string {
    return (value ?? '').trim().replace(/^@+/, '').toLowerCase();
}

/**
 * Stable per-handle colour for chips, legends, and chart lines.
 * Own / peer series stay on the dedicated You / average colours.
 */
export function snitchAccountColour(
    name: string | null | undefined,
    options: { isOwn?: boolean; isPeer?: boolean } = {},
): string {
    const label = (name ?? '').trim();

    if (
        options.isOwn
        || label === 'You'
        || label.toLowerCase() === 'you'
    ) {
        return snitchYouSeries();
    }

    if (
        options.isPeer
        || label === 'Peer median'
        || label === "Rivals' average"
    ) {
        return snitchPeerSeries();
    }

    const rivals = snitchRivalColours();
    const key = snitchNormalizeHandle(label);
    let hash = 0;

    for (let i = 0; i < key.length; i++) {
        hash = ((hash * 31) + key.charCodeAt(i)) >>> 0;
    }

    return rivals[hash % rivals.length] ?? rivals[0];
}

/** Rivals' average / peer median line. */
export function snitchPeerSeries(): string {
    return snitchIsDark() ? 'rgba(239, 230, 216, 0.55)' : '#8A8478';
}

/** Muted axis / grid line colour with readable contrast on paper. */
export function snitchAxisMuted(): string {
    return snitchIsDark() ? 'rgba(239, 230, 216, 0.35)' : 'rgba(28, 27, 26, 0.28)';
}

export function snitchAxisLabel(): string {
    return snitchIsDark() ? 'rgba(239, 230, 216, 0.7)' : '#5c5346';
}

export function snitchApexTheme(): { mode: 'dark' | 'light' } {
    return { mode: snitchIsDark() ? 'dark' : 'light' };
}
