<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';

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
}>();

const colours = ['#1C1B1A', '#3A5F6B', '#F0C400', '#7A6A4F', '#8B5A2B', '#5C6B73'];

const chartSeries = computed(() =>
    (props.series ?? [])
        .filter((row) => (row.points ?? []).some((point) => point.value !== null))
        .map((row) => ({
            name: row.name,
            data: (row.points ?? [])
                .filter((point) => point.value !== null)
                .map((point) => ({
                    x: point.date,
                    y: Number(point.value),
                })),
        })),
);

const options = computed(() => ({
    chart: {
        type: 'line' as const,
        height: props.height ?? 220,
        toolbar: { show: false },
        zoom: { enabled: false },
        fontFamily: 'inherit',
        animations: { enabled: false },
        background: 'transparent',
    },
    colors: colours,
    stroke: { width: 2, curve: 'straight' as const },
    markers: { size: 2.5 },
    grid: {
        borderColor: '#d6cbb8',
        strokeDashArray: 0,
        padding: { left: 8, right: 8 },
    },
    xaxis: {
        type: 'category' as const,
        labels: { style: { colors: '#5c5346', fontSize: '10px' }, rotate: -35 },
        axisBorder: { color: '#d6cbb8' },
        axisTicks: { color: '#d6cbb8' },
    },
    yaxis: {
        labels: {
            style: { colors: '#5c5346', fontSize: '10px' },
            formatter: (value: number) => {
                const suffix = props.unit === '%' ? '%' : props.unit === 'x' ? '×' : '';

                return `${Number(value).toFixed(props.unit === 'x' || props.unit === '%' ? 1 : 0)}${suffix}`;
            },
        },
    },
    legend: {
        fontSize: '11px',
        labels: { colors: '#1C1B1A' },
        position: 'top' as const,
    },
    tooltip: {
        y: {
            formatter: (value: number) => {
                const suffix = props.unit === '%' ? '%' : props.unit === 'x' ? '×' : '';

                return `${Number(value).toFixed(2)}${suffix}`;
            },
        },
    },
}));
</script>

<template>
    <section class="snitch-scrap space-y-2 p-3">
        <div class="flex items-baseline justify-between gap-2">
            <h2 class="font-display text-base text-snitch-ink">{{ title }}</h2>
            <p v-if="note" class="text-[11px] text-snitch-ink/55">{{ note }}</p>
        </div>
        <p v-if="!chartSeries.length" class="text-sm text-snitch-ink/60">
            No points yet for this range.
        </p>
        <VueApexCharts
            v-else
            type="line"
            :height="height ?? 220"
            :options="options"
            :series="chartSeries"
        />
    </section>
</template>
