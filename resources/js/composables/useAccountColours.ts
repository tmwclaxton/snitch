import { snitchInk } from '@/lib/snitchTheme';

const RIVAL_COLOURS = ['#3A5F6B', '#E5341D', '#5B7C99', '#8B5A2B', '#0F766E'] as const;

export function useAccountColours() {
    function colourFor(handle: string | null | undefined, isOwn = false): string {
        if (isOwn) {
            return snitchInk('#141414');
        }

        const key = (handle ?? '').toLowerCase();
        let hash = 0;

        for (let i = 0; i < key.length; i++) {
            hash = (hash * 31 + key.charCodeAt(i)) >>> 0;
        }

        return RIVAL_COLOURS[hash % RIVAL_COLOURS.length] ?? RIVAL_COLOURS[0];
    }

    return { colourFor, rivalColours: RIVAL_COLOURS };
}
