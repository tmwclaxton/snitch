<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import { useAccountColours } from '@/composables/useAccountColours';

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
        data: row.points.map((point) => ({
            x: point.date,
            y: point.pct_change ?? 0,
        })),
    })),
);

const colours = computed(() =>
    (props.series ?? []).map((row) => colourFor(row.handle, row.is_own_account)),
);

const options = computed(() => ({
    chart: { type: 'line' as const, height: 180, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
    colors: colours.value,
    stroke: { width: 2, curve: 'straight' as const },
    markers: { size: 3 },
    grid: { borderColor: '#e2e8f0', strokeDashArray: 3 },
    xaxis: {
        type: 'category' as const,
        labels: { style: { colors: '#64748b', fontSize: '10px' } },
    },
    yaxis: {
        labels: {
            style: { colors: '#64748b', fontSize: '10px' },
            formatter: (value: number) => `${value.toFixed(1)}%`,
        },
        title: { text: '% vs start', style: { color: '#94a3b8', fontSize: '10px' } },
    },
    legend: { fontSize: '11px', labels: { colors: '#475569' } },
    tooltip: {
        y: { formatter: (value: number) => `${value.toFixed(1)}%` },
    },
}));
</script>

<template>
    <EmptyState v-if="status === 'empty' || !series?.length" :reason="reason" compact />
    <div v-else>
        <p v-if="status === 'insufficient'" class="mb-1.5 text-xs text-slate-500">{{ reason }}</p>
        <VueApexCharts type="line" height="180" :options="options" :series="chartSeries" />
    </div>
</template>
