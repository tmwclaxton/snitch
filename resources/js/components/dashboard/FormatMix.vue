<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import { snitchApexTheme, snitchAxisLabel, snitchAxisMuted, snitchInk, snitchSpot } from '@/lib/snitchTheme';

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
const colours = [snitchSpot('#fcd700'), '#4fc3f7', '#7cffb2', '#ff8a3d'];

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
    chart: { type: 'bar' as const, stacked: true, stackType: '100%' as const, height: 180, toolbar: { show: false }, fontFamily: 'inherit', background: 'transparent' },
    theme: snitchApexTheme(),
    colors: colours,
    plotOptions: { bar: { horizontal: true, barHeight: '55%' } },
    dataLabels: { enabled: false },
    xaxis: { categories: categories.value, labels: { style: { colors: snitchAxisLabel(), fontSize: '14px' } } },
    yaxis: { labels: { style: { colors: snitchAxisLabel(), fontSize: '14px' } } },
    legend: { fontSize: '14px', labels: { colors: snitchInk('#edeae2') } },
    grid: { borderColor: snitchAxisMuted() },
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
