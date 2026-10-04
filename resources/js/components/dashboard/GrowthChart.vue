<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import { useAccountColours } from '@/composables/useAccountColours';
import {
    connectedLineData,
    rivalStrokeWidth,
    youStrokeWidth,
} from '@/lib/lineChart';
import { snitchApexTheme, snitchAxisLabel, snitchAxisMuted, snitchInk } from '@/lib/snitchTheme';

type Point = { date: string; followers: number; pct_change: number | null };
type Series = { handle: string; is_own_account: boolean; points: Point[] };

const props = defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    series?: Series[];
}>();

const { colourFor } = useAccountColours();

const chartSeries = computed(() =>
    (props.series ?? []).map((row) => ({
        name: row.is_own_account ? 'You' : `@${row.handle}`,
        data: connectedLineData(
            row.points.map((point) => ({
                date: point.date,
                value: point.pct_change,
            })),
        ),
    })).filter((row) => row.data.length > 0),
);

const colours = computed(() =>
    (props.series ?? [])
        .filter((row) => row.points.some((point) => point.pct_change !== null))
        .map((row) => colourFor(row.handle, row.is_own_account)),
);

const strokeWidths = computed(() =>
    (props.series ?? [])
        .filter((row) => row.points.some((point) => point.pct_change !== null))
        .map((row) => (row.is_own_account ? youStrokeWidth() : rivalStrokeWidth())),
);

const options = computed(() => ({
    chart: {
        type: 'line' as const,
        height: 180,
        toolbar: { show: false },
        zoom: { enabled: false },
        fontFamily: 'inherit',
        background: 'transparent',
        animations: { enabled: false },
    },
    theme: snitchApexTheme(),
    colors: colours.value,
    stroke: {
        width: strokeWidths.value,
        curve: 'straight' as const,
        lineCap: 'round' as const,
    },
    markers: { size: 3, strokeWidth: 0 },
    grid: { borderColor: snitchAxisMuted(), strokeDashArray: 3 },
    xaxis: {
        type: 'datetime' as const,
        labels: { style: { colors: snitchAxisLabel(), fontSize: '14px' }, datetimeUTC: true },
    },
    yaxis: {
        labels: {
            style: { colors: snitchAxisLabel(), fontSize: '14px' },
            formatter: (value: number) => `${value.toFixed(1)}%`,
        },
        title: { text: '% vs start', style: { color: snitchAxisLabel(), fontSize: '14px' } },
    },
    legend: { fontSize: '14px', labels: { colors: snitchInk('#141414') } },
    tooltip: {
        y: { formatter: (value: number) => `${value.toFixed(1)}%` },
    },
}));
</script>

<template>
    <EmptyState v-if="status === 'empty' || !series?.length" :reason="reason" compact />
    <div v-else>
        <p v-if="status === 'insufficient'" class="mb-1.5 text-xs text-snitch-ink/55">{{ reason }}</p>
        <VueApexCharts
            v-if="chartSeries.length"
            type="line"
            height="180"
            :options="options"
            :series="chartSeries"
        />
    </div>
</template>
