<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import { useAccountColours } from '@/composables/useAccountColours';
import { snitchApexTheme, snitchAxisLabel, snitchAxisMuted, snitchInk } from '@/lib/snitchTheme';

type Point = {
    handle: string;
    is_own_account: boolean;
    x: number;
    y: number;
    followers: number | null;
    n: number;
};

const props = defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    points?: Point[];
    medianX?: number | null;
    medianY?: number | null;
}>();

const { colourFor } = useAccountColours();

const chartSeries = computed(() =>
    (props.points ?? []).map((point) => ({
        name: point.is_own_account ? 'You' : `@${point.handle}`,
        data: [[point.x, point.y, Math.max(8, Math.sqrt(point.followers ?? 100))]],
    })),
);

const colours = computed(() =>
    (props.points ?? []).map((point) => colourFor(point.handle, point.is_own_account)),
);

const options = computed(() => ({
    chart: { type: 'bubble' as const, height: 180, toolbar: { show: false }, fontFamily: 'inherit', background: 'transparent' },
    theme: snitchApexTheme(),
    colors: colours.value,
    dataLabels: { enabled: false },
    grid: { borderColor: snitchAxisMuted(), strokeDashArray: 3 },
    xaxis: {
        tickAmount: 4,
        title: { text: 'Posts / week', style: { color: snitchAxisLabel(), fontSize: '14px' } },
        labels: { style: { colors: snitchAxisLabel(), fontSize: '14px' } },
    },
    yaxis: {
        title: { text: 'ER %', style: { color: snitchAxisLabel(), fontSize: '14px' } },
        labels: { style: { colors: snitchAxisLabel(), fontSize: '14px' } },
    },
    legend: { fontSize: '14px', labels: { colors: snitchInk('#edeae2') } },
    annotations: {
        xaxis: props.medianX != null
            ? [{ x: props.medianX, borderColor: snitchAxisMuted(), strokeDashArray: 4, label: { text: "rivals' avg", style: { fontSize: '14px', color: snitchAxisLabel(), background: 'transparent' } } }]
            : [],
        yaxis: props.medianY != null
            ? [{ y: props.medianY, borderColor: snitchAxisMuted(), strokeDashArray: 4, label: { text: "rivals' avg", style: { fontSize: '14px', color: snitchAxisLabel(), background: 'transparent' } } }]
            : [],
    },
}));
</script>

<template>
    <EmptyState v-if="status !== 'ok' || !points?.length" :reason="reason" compact />
    <VueApexCharts v-else type="bubble" height="180" :options="options" :series="chartSeries" />
</template>
