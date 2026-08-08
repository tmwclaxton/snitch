export type PostMetrics = {
    views?: number | null;
    likes?: number | null;
    comments?: number | null;
    shares?: number | null;
    [key: string]: number | null | undefined;
};

const METRIC_ORDER = ['views', 'likes', 'comments', 'shares'] as const;

export function formatCompactCount(value: number): string {
    if (!Number.isFinite(value) || value <= 0) {
        return '';
    }

    if (value >= 1_000_000) {
        return `${(value / 1_000_000).toFixed(value >= 10_000_000 ? 0 : 1).replace(/\.0$/, '')}M`;
    }

    if (value >= 1_000) {
        return `${(value / 1_000).toFixed(value >= 10_000 ? 0 : 1).replace(/\.0$/, '')}k`;
    }

    return String(Math.round(value));
}

export function metricPairs(
    metrics: PostMetrics | null | undefined,
): Array<{ key: string; label: string; value: string }> {
    if (!metrics) {
        return [];
    }

    const pairs: Array<{ key: string; label: string; value: string }> = [];

    for (const key of METRIC_ORDER) {
        const raw = metrics[key];

        if (typeof raw !== 'number' || raw <= 0) {
            continue;
        }

        const value = formatCompactCount(raw);

        if (!value) {
            continue;
        }

        pairs.push({
            key,
            label: key.charAt(0).toUpperCase() + key.slice(1),
            value,
        });
    }

    return pairs;
}
