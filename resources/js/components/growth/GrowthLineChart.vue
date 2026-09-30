<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { snitchAxisLabel, snitchAxisMuted, snitchInk } from '@/lib/snitchTheme';

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

/** Evidence File: ink for You (AA on cream/night); muted peer; palette rivals. */
const PEER_COLOUR = '#8A8478';
const RIVAL_COLOURS = ['#3A5F6B', '#E5341D', '#5B7C99', '#8B5A2B', '#2F6F4E', '#6B4C7A', '#B45309', '#0F766E'];

const colourByName = computed(() => {
    const map = new Map<string, string>();
    let rivalIndex = 0;
    const youColour = snitchInk('#141414');

    for (const row of props.series ?? []) {
        if (row.is_own_account || row.name === 'You') {
            map.set(row.name, youColour);
        } else if (row.is_peer_median || row.name === 'Peer median' || row.name === "Rivals' average") {
            map.set(row.name, PEER_COLOUR);
        } else {
            map.set(row.name, RIVAL_COLOURS[rivalIndex % RIVAL_COLOURS.length]);
            rivalIndex++;
        }
    }

    return map;
});

/** Shared weekly categories so every series aligns on the same x-axis. */
const categories = computed(() => {
    const dates = new Set<string>();

    for (const row of props.series ?? []) {
        for (const point of row.points ?? []) {
            if (point.date) {
                dates.add(point.date);
            }
        }
    }

    return [...dates].sort();
});

const activeSeries = computed(() =>
    (props.series ?? []).filter((row) => (row.points ?? []).some((point) => point.value !== null)),
);

const chartSeries = computed(() =>
    activeSeries.value.map((row) => {
        const byDate = new Map((row.points ?? []).map((point) => [point.date, point.value]));

        return {
            name: row.name,
            data: categories.value.map((date) => {
                const value = byDate.get(date);

                return value === undefined || value === null ? null : Number(value);
            }),
        };
    }),
);

const strokeWidths = computed(() =>
    activeSeries.value.map((row) => (row.is_own_account || row.name === 'You' ? 3.5 : 2)),
);

const strokeDashes = computed(() =>
    activeSeries.value.map((row) => (row.is_peer_median || row.name === 'Peer median' || row.name === "Rivals' average" ? 8 : 0)),
);

const colours = computed(() =>
    activeSeries.value.map((row) => colourByName.value.get(row.name) ?? PEER_COLOUR),
);

const chartKey = computed(() =>
    activeSeries.value.map((row) => row.name).join('|') + ':' + categories.value.join(','),
);

const tableRows = computed(() =>
    (props.series ?? [])
        .filter((row) => !row.is_peer_median && row.name !== 'Peer median' && row.name !== "Rivals' average")
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

function formatAxisDate(value: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (!match) {
        return value;
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
    colors: colours.value,
    stroke: {
        width: strokeWidths.value,
        curve: 'straight' as const,
        dashArray: strokeDashes.value,
        lineCap: 'round' as const,
    },
    markers: {
        size: activeSeries.value.map((row) => (row.is_peer_median || row.name === 'Peer median' || row.name === "Rivals' average" ? 0 : 3)),
        strokeWidth: 0,
        hover: { size: 4 },
    },
    grid: {
        borderColor: snitchAxisMuted(),
        strokeDashArray: 0,
        padding: { left: 8, right: 12, bottom: 0 },
    },
    xaxis: {
        type: 'category' as const,
        categories: categories.value,
        tickPlacement: 'on' as const,
        labels: {
            style: { colors: snitchAxisLabel(), fontSize: '14px' },
            rotate: categories.value.length > 8 ? -35 : 0,
            hideOverlappingLabels: false,
            formatter: (value: string) => formatAxisDate(String(value)),
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
        y: {
            formatter: (value: number | null) => {
                if (value === null || Number.isNaN(value)) {
                    return '-';
                }

                const suffix = props.unit === '%' ? '%' : props.unit === 'x' ? '×' : '';

                return `${Number(value).toFixed(2)}${suffix}`;
            },
        },
        x: {
            formatter: (value: string) => formatAxisDate(String(value)),
        },
    },
}));
</script>

<template>
    <section class="snitch-scrap flex h-full min-h-[280px] flex-col space-y-2 p-3">
        <div class="flex items-baseline justify-between gap-2">
            <h2 class="font-display text-base text-snitch-ink">{{ title }}</h2>
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
