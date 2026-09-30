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

export function snitchInk(fallback = '#141414'): string {
    return snitchCssVar('--snitch-ink', fallback);
}

export function snitchPaper(fallback = '#f3eee3'): string {
    return snitchCssVar('--snitch-paper', fallback);
}

export function snitchFog(fallback = '#ebe4d6'): string {
    return snitchCssVar('--snitch-fog', fallback);
}

export function snitchSpot(fallback = '#ffd60a'): string {
    return snitchCssVar('--snitch-spot', fallback);
}

/** "You" series: charcoal on light paper, Caution Tape yellow on night. */
export function snitchYouSeries(): string {
    return snitchIsDark() ? '#fcd700' : snitchInk('#141414');
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
    '#FF3D8B',
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

/** Rivals' average / peer median line. */
export function snitchPeerSeries(): string {
    return snitchIsDark() ? 'rgba(237, 234, 226, 0.55)' : '#8A8478';
}

/** Muted axis / grid line colour with readable contrast on paper. */
export function snitchAxisMuted(): string {
    return snitchIsDark() ? 'rgba(237, 234, 226, 0.35)' : 'rgba(20, 20, 20, 0.28)';
}

export function snitchAxisLabel(): string {
    return snitchIsDark() ? 'rgba(237, 234, 226, 0.7)' : '#5c5346';
}
