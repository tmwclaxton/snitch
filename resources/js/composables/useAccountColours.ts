const RIVAL_COLOURS = ['#475569', '#0f766e', '#b45309', '#7c3aed', '#be123c'] as const;

export function useAccountColours() {
    function colourFor(handle: string | null | undefined, isOwn = false): string {
        if (isOwn) {
            return '#0f172a';
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
