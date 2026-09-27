<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import { useAccountColours } from '@/composables/useAccountColours';

type Row = { handle: string; is_own_account: boolean; eng_share: number; post_share: number };

const props = defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    rows?: Row[];
}>();

const { colourFor } = useAccountColours();

const colours = computed(() =>
    (props.rows ?? []).map((row) => colourFor(row.handle, row.is_own_account)),
);

const engStacked = computed(() =>
    (props.rows ?? []).map((row) => ({
        name: row.is_own_account ? 'You' : `@${row.handle}`,
        data: [row.eng_share],
    })),
);

const postStacked = computed(() =>
    (props.rows ?? []).map((row) => ({
        name: row.is_own_account ? 'You' : `@${row.handle}`,
        data: [row.post_share],
    })),
);

function optionsFor(title: string) {
    return {
        chart: {
            type: 'bar' as const,
            stacked: true,
            stackType: '100%' as const,
            height: 100,
            toolbar: { show: false },
            fontFamily: 'inherit',
        },
        colors: colours.value,
        plotOptions: { bar: { horizontal: true, barHeight: '60%' } },
        dataLabels: { enabled: false },
        xaxis: {
            categories: [title],
            max: 100,
            labels: { style: { colors: '#64748b', fontSize: '10px' } },
        },
        yaxis: { labels: { show: false } },
        legend: { fontSize: '10px', labels: { colors: '#475569' } },
        grid: { show: false },
        tooltip: { y: { formatter: (value: number) => `${value.toFixed(1)}%` } },
    };
}

const engOptions = computed(() => optionsFor('Engagement share'));
const postOptions = computed(() => optionsFor('Posting share'));
</script>

<template>
    <EmptyState v-if="status !== 'ok'" :reason="reason" compact />
    <div v-else class="space-y-1">
        <p class="text-[10px] text-slate-400">Proxy share of attention (not true SOV).</p>
        <VueApexCharts type="bar" height="100" :options="engOptions" :series="engStacked" />
        <VueApexCharts type="bar" height="100" :options="postOptions" :series="postStacked" />
    </div>
</template>
