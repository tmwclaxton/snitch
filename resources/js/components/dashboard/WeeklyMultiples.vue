<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import { useAccountColours } from '@/composables/useAccountColours';
import {
    connectedLineData,
    lineChartTimestamp,
    rivalStrokeWidth,
    youStrokeWidth,
} from '@/lib/lineChart';
import { snitchApexTheme, snitchAxisLabel, snitchAxisMuted, snitchInk } from '@/lib/snitchTheme';

type Point = { label: string; posts: number; interactions: number; er: number | null; followers: number | null };
type Series = { handle: string; is_own_account: boolean; points: Point[] };

const props = defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    weeks?: string[];
    series?: Series[];
    deltas?: { posts: number; er: number | null } | null;
}>();

const { colourFor } = useAccountColours();

const postSeries = computed(() =>
    (props.series ?? []).map((row) => {
        const weeks = props.weeks ?? [];

        return {
            name: row.is_own_account ? 'You' : `@${row.handle}`,
            data: connectedLineData(
                row.points.map((point, index) => ({
                    date: weeks[index] ?? point.label,
                    value: point.posts,
                })),
            ),
        };
    }).filter((row) => row.data.length > 0),
);

const activeRows = computed(() =>
    (props.series ?? []).filter((row) => {
        const name = row.is_own_account ? 'You' : `@${row.handle}`;

        return postSeries.value.some((series) => series.name === name);
    }),
);

const colours = computed(() =>
    activeRows.value.map((row) => colourFor(row.handle, row.is_own_account)),
);

const strokeWidths = computed(() =>
    activeRows.value.map((row) => (row.is_own_account ? youStrokeWidth() : rivalStrokeWidth())),
);

const options = computed(() => ({
    chart: {
        type: 'line' as const,
        height: 160,
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
    markers: { size: 2, strokeWidth: 0 },
    grid: { borderColor: snitchAxisMuted(), strokeDashArray: 3 },
    xaxis: {
        type: 'datetime' as const,
        labels: {
            style: { colors: snitchAxisLabel(), fontSize: '14px' },
            datetimeUTC: true,
            rotate: -35,
        },
        min: props.weeks?.[0] ? lineChartTimestamp(props.weeks[0]) : undefined,
        max: props.weeks?.length
            ? lineChartTimestamp(props.weeks[props.weeks.length - 1] ?? '')
            : undefined,
    },
    yaxis: {
        labels: { style: { colors: snitchAxisLabel(), fontSize: '14px' } },
        title: { text: 'posts/wk', style: { color: snitchAxisLabel(), fontSize: '14px' } },
    },
    legend: { fontSize: '14px', labels: { colors: snitchInk('#141414') } },
}));
</script>

<template>
    <EmptyState v-if="status !== 'ok'" :reason="reason" compact />
    <div v-else>
        <p v-if="deltas" class="mb-1 text-xs text-snitch-ink/55">
            This week vs last:
            <span class="font-medium text-snitch-ink">posts {{ deltas.posts >= 0 ? '+' : '' }}{{ deltas.posts }}</span>
            <span v-if="deltas.er != null" class="ml-2 font-medium text-snitch-ink">
                ER {{ deltas.er >= 0 ? '+' : '' }}{{ deltas.er.toFixed(1) }} points
            </span>
        </p>
        <VueApexCharts
            v-if="postSeries.length"
            type="line"
            height="160"
            :options="options"
            :series="postSeries"
        />
    </div>
</template>
