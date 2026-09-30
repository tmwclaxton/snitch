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

/** Muted axis / grid line colour with readable contrast on paper. */
export function snitchAxisMuted(): string {
    const isDark = document.documentElement.classList.contains('dark');

    return isDark ? 'rgba(237, 234, 226, 0.35)' : 'rgba(20, 20, 20, 0.28)';
}

export function snitchAxisLabel(): string {
    const isDark = document.documentElement.classList.contains('dark');

    return isDark ? 'rgba(237, 234, 226, 0.7)' : '#5c5346';
}
