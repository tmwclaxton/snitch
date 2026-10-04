<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import {
    connectedLineData,
    rivalStrokeWidth,
    youStrokeWidth,
} from '@/lib/lineChart';
import {
    snitchAccountColour,
    snitchApexTheme,
    snitchAxisLabel,
    snitchAxisMuted,
    snitchInk,
    snitchPeerSeries,
} from '@/lib/snitchTheme';

type Point = { date: string; value: number | null };
type Series = {
    name: string;
    is_own_account?: boolean;
    is_peer_median?: boolean;
    points: Point[];
};

const props = defineProps<{
    title: string;
    series: Series[];
    unit?: string;
    note?: string | null;
    height?: number;
    /** When true, replace the chart with a compact snapshot table. */
    tableFallback?: boolean;
}>();

function isYou(row: Series): boolean {
    return Boolean(row.is_own_account) || row.name === 'You';
}

function isPeer(row: Series): boolean {
    return Boolean(row.is_peer_median)
        || row.name === 'Peer median'
        || row.name === "Rivals' average";
}

const colourByName = computed(() => {
    const map = new Map<string, string>();

    for (const row of props.series ?? []) {
        map.set(
            row.name,
            snitchAccountColour(row.name, {
                isOwn: isYou(row),
                isPeer: isPeer(row),
            }),
        );
    }

    return map;
});

const activeSeries = computed(() =>
    (props.series ?? []).filter((row) => connectedLineData(row.points).length > 0),
);

/** Only real points - ApexCharts 7 cannot join null category slots, so drop gaps client-side. */
const chartSeries = computed(() =>
    activeSeries.value.map((row) => ({
        name: row.name,
        data: connectedLineData(row.points),
    })),
);

const strokeWidths = computed(() =>
    activeSeries.value.map((row) => (isYou(row) ? youStrokeWidth() : rivalStrokeWidth())),
);

const strokeDashes = computed(() =>
    activeSeries.value.map((row) => (isPeer(row) ? 8 : 0)),
);

const colours = computed(() =>
    activeSeries.value.map((row) => colourByName.value.get(row.name) ?? snitchPeerSeries()),
);

const chartKey = computed(() =>
    chartSeries.value
        .map((row) => `${row.name}:${row.data.map((point) => `${point.x}=${point.y}`).join(',')}`)
        .join('|'),
);

const tableRows = computed(() =>
    (props.series ?? [])
        .filter((row) => !isPeer(row))
        .map((row) => {
            const last = [...(row.points ?? [])].reverse().find((point) => point.value !== null);

            return {
                name: row.name,
                value: last?.value ?? null,
                date: last?.date ?? null,
            };
        })
        .filter((row) => row.value !== null),
);

function formatAxisDate(value: string | number): string {
    if (typeof value === 'number' && Number.isFinite(value)) {
        const date = new Date(value);
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        return `${date.getUTCDate()} ${months[date.getUTCMonth()] ?? ''}`;
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value));

    if (!match) {
        return String(value);
    }

    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = months[Number(match[2]) - 1] ?? match[2];

    return `${Number(match[3])} ${month}`;
}

const options = computed(() => ({
    chart: {
        type: 'line' as const,
        height: props.height ?? 240,
        toolbar: { show: false },
        zoom: { enabled: false },
        fontFamily: 'inherit',
        animations: { enabled: false },
        background: 'transparent',
    },
    theme: snitchApexTheme(),
    colors: colours.value,
    stroke: {
        width: strokeWidths.value,
        curve: 'straight' as const,
        dashArray: strokeDashes.value,
        lineCap: 'round' as const,
    },
    markers: {
        size: activeSeries.value.map((row) => {
            if (isPeer(row)) {
                return 0;
            }

            return isYou(row) ? 4 : 3;
        }),
        strokeWidth: 0,
        hover: { size: 5 },
    },
    grid: {
        borderColor: snitchAxisMuted(),
        strokeDashArray: 0,
        padding: { left: 8, right: 12, bottom: 0 },
    },
    xaxis: {
        type: 'datetime' as const,
        labels: {
            style: { colors: snitchAxisLabel(), fontSize: '14px' },
            datetimeUTC: true,
            formatter: (value: string | number) => formatAxisDate(value),
        },
        axisBorder: { color: snitchAxisMuted() },
        axisTicks: { color: snitchAxisMuted() },
    },
    yaxis: {
        labels: {
            style: { colors: snitchAxisLabel(), fontSize: '14px' },
            formatter: (value: number) => {
                const suffix = props.unit === '%' ? '%' : props.unit === 'x' ? '×' : '';

                return `${Number(value).toFixed(props.unit === 'x' || props.unit === '%' ? 1 : 0)}${suffix}`;
            },
        },
    },
    legend: {
        fontSize: '14px',
        labels: { colors: snitchInk('#1C1B1A') },
        position: 'top' as const,
        showForSingleSeries: true,
    },
    tooltip: {
        shared: true,
        intersect: false,
        x: {
            formatter: (value: string | number) => formatAxisDate(value),
        },
        y: {
            formatter: (value: number | null) => {
                if (value === null || Number.isNaN(value)) {
                    return '-';
                }

                const suffix = props.unit === '%' ? '%' : props.unit === 'x' ? '×' : '';

                return `${Number(value).toFixed(2)}${suffix}`;
            },
        },
    },
}));
</script>

<template>
    <section class="snitch-scrap flex h-full min-h-[280px] flex-col space-y-2 p-3">
        <div class="flex items-baseline justify-between gap-2">
            <h2 class="snitch-hero-display text-base text-snitch-ink">{{ title }}</h2>
        </div>

        <template v-if="tableFallback">
            <p
                v-if="note"
                class="text-sm text-snitch-ink/65"
            >
                {{ note }}
            </p>
            <table
                v-if="tableRows.length"
                class="w-full text-left text-sm text-snitch-ink"
            >
                <thead>
                    <tr class="border-b border-snitch-ink/15 text-xs uppercase tracking-wide text-snitch-ink/55">
                        <th class="py-1.5 pr-2 font-medium">Account</th>
                        <th class="py-1.5 pr-2 font-medium">Followers</th>
                        <th class="py-1.5 font-medium">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in tableRows"
                        :key="row.name"
                        class="border-b border-snitch-ink/8"
                    >
                        <td class="py-1.5 pr-2">{{ row.name }}</td>
                        <td class="py-1.5 pr-2 tabular-nums">{{ row.value?.toLocaleString() }}</td>
                        <td class="py-1.5 text-snitch-ink/70">{{ row.date ? formatAxisDate(row.date) : '-' }}</td>
                    </tr>
                </tbody>
            </table>
            <p
                v-else
                class="text-sm text-snitch-ink/60"
            >
                No snapshots yet for this range.
            </p>
        </template>

        <p
            v-else-if="!chartSeries.length"
            class="text-sm text-snitch-ink/60"
        >
            No points yet for this range.
        </p>
        <VueApexCharts
            v-else
            :key="chartKey"
            class="min-h-0 flex-1"
            type="line"
            :height="height ?? 240"
            :options="options"
            :series="chartSeries"
        />
    </section>
</template>
