<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import EmptyState from '@/components/dashboard/EmptyState.vue';

type Row = {
    handle: string;
    is_own_account: boolean;
    n: number;
    shares: Record<string, number>;
};

const props = defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    rows?: Row[];
}>();

const formats = ['Reel', 'Carousel', 'Image', 'Video'] as const;
const colours = ['#0f172a', '#0f766e', '#b45309', '#64748b'];

const categories = computed(() =>
    (props.rows ?? []).map((row) => (row.is_own_account ? 'You' : `@${row.handle}`)),
);

const chartSeries = computed(() =>
    formats.map((format) => ({
        name: format,
        data: (props.rows ?? []).map((row) => row.shares[format] ?? 0),
    })),
);

const options = computed(() => ({
    chart: { type: 'bar' as const, stacked: true, stackType: '100%' as const, height: 180, toolbar: { show: false }, fontFamily: 'inherit' },
    colors: colours,
    plotOptions: { bar: { horizontal: true, barHeight: '55%' } },
    dataLabels: { enabled: false },
    xaxis: { categories: categories.value, labels: { style: { colors: '#64748b', fontSize: '10px' } } },
    yaxis: { labels: { style: { colors: '#64748b', fontSize: '10px' } } },
    legend: { fontSize: '11px', labels: { colors: '#475569' } },
    grid: { borderColor: '#e2e8f0' },
    tooltip: { y: { formatter: (value: number) => `${value.toFixed(0)}%` } },
}));
</script>

<template>
    <EmptyState v-if="status === 'empty' || !rows?.length" :reason="reason" compact />
    <div v-else>
        <p v-if="status === 'insufficient'" class="mb-1.5 text-xs text-snitch-ink/55">{{ reason }}</p>
        <VueApexCharts type="bar" height="180" :options="options" :series="chartSeries" />
    </div>
</template>
