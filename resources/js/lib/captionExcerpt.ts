/**
 * Build a sentence-ending excerpt for long captions. Never cut mid-word.
 */
export function captionExcerpt(
    caption: string | null | undefined,
    maxChars = 220,
): { excerpt: string; truncated: boolean } {
    const text = (caption ?? '').trim();

    if (text === '') {
        return { excerpt: '', truncated: false };
    }

    if (text.length <= maxChars) {
        return { excerpt: text, truncated: false };
    }

    const window = text.slice(0, maxChars + 1);
    const sentenceEnds = [...window.matchAll(/[.!?](?=\s|$)/g)];
    const lastSentence = sentenceEnds.at(-1);

    if (lastSentence && (lastSentence.index ?? 0) >= Math.floor(maxChars * 0.45)) {
        const end = (lastSentence.index ?? 0) + 1;

        return { excerpt: text.slice(0, end).trim(), truncated: true };
    }

    const slice = text.slice(0, maxChars);
    const lastSpace = slice.lastIndexOf(' ');

    if (lastSpace >= Math.floor(maxChars * 0.5)) {
        return {
            excerpt: slice.slice(0, lastSpace).replace(/[.,;:]+$/u, '').trim() + '…',
            truncated: true,
        };
    }

    return { excerpt: slice.trim() + '…', truncated: true };
}
