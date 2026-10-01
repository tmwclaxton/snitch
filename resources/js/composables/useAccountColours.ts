import { snitchAccountColour, snitchRivalColours } from '@/lib/snitchTheme';

export function useAccountColours() {
    function colourFor(handle: string | null | undefined, isOwn = false): string {
        return snitchAccountColour(handle, { isOwn });
    }

    return { colourFor, rivalColours: snitchRivalColours() };
}
