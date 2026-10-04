export type HighlightPart = {
    text: string;
    highlight: boolean;
};

const MULTIPLIER = /\d+(?:\.\d+)?\s*[×xX]/;

const PREFERRED = [
    'carousels',
    'carousel',
    'reels',
    'reel',
    'stories',
    'ads',
    'rivals',
    'tracking',
    'vote',
    'week',
    'growth',
    'followers',
    'engagement',
    'report',
    'dashboard',
];

const SKIP = new Set([
    'a',
    'an',
    'the',
    'and',
    'or',
    'to',
    'of',
    'on',
    'at',
    'in',
    'for',
    'vs',
    'we',
    'you',
    'your',
    'is',
    'are',
    'post',
    'add',
    'sync',
    'how',
    'what',
    'get',
    'see',
    'more',
    'from',
    'with',
    'this',
    'that',
    'they',
    'their',
    'none',
    'pick',
]);

function splitAround(text: string, start: number, length: number): HighlightPart[] {
    const end = start + length;
    const parts: HighlightPart[] = [];

    if (start > 0) {
        parts.push({ text: text.slice(0, start), highlight: false });
    }

    parts.push({ text: text.slice(start, end), highlight: true });

    if (end < text.length) {
        parts.push({ text: text.slice(end), highlight: false });
    }

    return parts;
}

export function highlightKeyPhrase(text: string): HighlightPart[] {
    const multiplier = MULTIPLIER.exec(text);

    if (multiplier && multiplier.index != null) {
        return splitAround(text, multiplier.index, multiplier[0].length);
    }

    const lower = text.toLowerCase();

    for (const word of PREFERRED) {
        const index = lower.search(new RegExp(`\\b${word}\\b`, 'i'));

        if (index >= 0) {
            return splitAround(text, index, word.length);
        }
    }

    const tokens = [...text.matchAll(/\b([A-Za-z][A-Za-z'-]{3,})\b/g)];

    for (const token of tokens) {
        if (token.index == null) {
            continue;
        }

        if (SKIP.has(token[1].toLowerCase())) {
            continue;
        }

        return splitAround(text, token.index, token[0].length);
    }

    return [{ text, highlight: false }];
}
