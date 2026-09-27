<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import { useAccountColours } from '@/composables/useAccountColours';

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
    (props.series ?? []).map((row) => ({
        name: row.is_own_account ? 'You' : `@${row.handle}`,
        data: row.points.map((point) => point.posts),
    })),
);

const colours = computed(() =>
    (props.series ?? []).map((row) => colourFor(row.handle, row.is_own_account)),
);

const options = computed(() => ({
    chart: { type: 'line' as const, height: 160, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
    colors: colours.value,
    stroke: { width: 2, curve: 'straight' as const },
    markers: { size: 2 },
    grid: { borderColor: '#e2e8f0', strokeDashArray: 3 },
    xaxis: {
        categories: props.weeks ?? [],
        labels: { style: { colors: '#64748b', fontSize: '9px' }, rotate: -35 },
    },
    yaxis: {
        labels: { style: { colors: '#64748b', fontSize: '9px' } },
        title: { text: 'posts/wk', style: { color: '#94a3b8', fontSize: '9px' } },
    },
    legend: { fontSize: '10px', labels: { colors: '#475569' } },
}));
</script>

<template>
    <EmptyState v-if="status !== 'ok'" :reason="reason" compact />
    <div v-else>
        <p v-if="deltas" class="mb-1 text-[11px] text-slate-500">
            This week vs last:
            <span class="font-medium text-slate-800">posts {{ deltas.posts >= 0 ? '+' : '' }}{{ deltas.posts }}</span>
            <span v-if="deltas.er != null" class="ml-2 font-medium text-slate-800">
                ER {{ deltas.er >= 0 ? '+' : '' }}{{ deltas.er.toFixed(1) }}pp
            </span>
        </p>
        <VueApexCharts type="line" height="160" :options="options" :series="postSeries" />
    </div>
</template>
