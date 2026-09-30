import { snitchRivalColours, snitchYouSeries } from '@/lib/snitchTheme';

export function useAccountColours() {
    function colourFor(handle: string | null | undefined, isOwn = false): string {
        if (isOwn) {
            return snitchYouSeries();
        }

        const rivals = snitchRivalColours();
        const key = (handle ?? '').toLowerCase();
        let hash = 0;

        for (let i = 0; i < key.length; i++) {
            hash = (hash * 31 + key.charCodeAt(i)) >>> 0;
        }

        return rivals[hash % rivals.length] ?? rivals[0];
    }

    return { colourFor, rivalColours: snitchRivalColours() };
}
