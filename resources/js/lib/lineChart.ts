/**
 * Shared helpers for Apex line charts that must join points across gaps.
 * ApexCharts 7 removed stroke.connectNulls; null category slots break paths.
 */

export type LinePoint = {
    date: string;
    value: number | null;
};

export type ConnectedLineDatum = {
    x: number;
    y: number;
};

/** Parse a Y-m-d (or ISO) date to a UTC-noon timestamp for stable datetime axes. */
export function lineChartTimestamp(date: string): number {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(date.trim());

    if (!match) {
        const parsed = Date.parse(date);

        return Number.isFinite(parsed) ? parsed : 0;
    }

    return Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12, 0, 0);
}

/** Drop null/missing weeks so the stroke joins known points. */
export function connectedLineData(points: LinePoint[] | null | undefined): ConnectedLineDatum[] {
    const out: ConnectedLineDatum[] = [];

    for (const point of points ?? []) {
        if (!point?.date || point.value === null || point.value === undefined) {
            continue;
        }

        const y = Number(point.value);

        if (!Number.isFinite(y)) {
            continue;
        }

        out.push({
            x: lineChartTimestamp(point.date),
            y,
        });
    }

    return out.sort((a, b) => a.x - b.x);
}

export function youStrokeWidth(): number {
    return 3;
}

export function rivalStrokeWidth(): number {
    return 2;
}
