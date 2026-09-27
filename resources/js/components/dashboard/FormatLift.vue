<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import EmptyState from '@/components/dashboard/EmptyState.vue';

type Row = {
    handle: string;
    is_own_account: boolean;
    lifts: Record<string, { lift: number; n: number }>;
};

const props = defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    rows?: Row[];
    peerMedianLift?: Record<string, number | null>;
}>();

const formats = computed(() => {
    const keys = new Set<string>();

    for (const row of props.rows ?? []) {
        Object.keys(row.lifts).forEach((key) => keys.add(key));
    }

    return [...keys];
});

const chartSeries = computed(() =>
    (props.rows ?? []).map((row) => ({
        name: row.is_own_account ? 'You' : `@${row.handle}`,
        data: formats.value.map((format) => row.lifts[format]?.lift ?? null),
    })),
);

const options = computed(() => ({
    chart: { type: 'bar' as const, height: 180, toolbar: { show: false }, fontFamily: 'inherit' },
    plotOptions: { bar: { columnWidth: '55%' } },
    dataLabels: { enabled: false },
    xaxis: {
        categories: formats.value,
        labels: { style: { colors: '#64748b', fontSize: '10px' } },
    },
    yaxis: {
        title: { text: '× usual', style: { color: '#94a3b8', fontSize: '10px' } },
        labels: { style: { colors: '#64748b', fontSize: '10px' } },
    },
    annotations: {
        yaxis: [{ y: 1, borderColor: '#94a3b8', strokeDashArray: 4, label: { text: '1.0×', style: { fontSize: '9px' } } }],
    },
    legend: { fontSize: '11px', labels: { colors: '#475569' } },
    grid: { borderColor: '#e2e8f0' },
}));
</script>

<template>
    <EmptyState v-if="status !== 'ok' || !rows?.length" :reason="reason" compact />
    <div v-else class="space-y-2">
        <VueApexCharts type="bar" height="160" :options="options" :series="chartSeries" />
        <ul class="grid gap-1 sm:grid-cols-2">
            <li
                v-for="row in rows"
                :key="row.handle"
                class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10px] text-slate-600"
            >
                <span class="font-medium text-slate-800">{{ row.is_own_account ? 'You' : `@${row.handle}` }}</span>
                <span
                    v-for="format in formats"
                    :key="`${row.handle}-${format}`"
                    class="tabular-nums"
                >
                    {{ format }}
                    <template v-if="row.lifts[format]">
                        {{ row.lifts[format].lift.toFixed(2) }}×
                        <span class="text-slate-400">(n={{ row.lifts[format].n }})</span>
                    </template>
                    <template v-else>—</template>
                </span>
            </li>
        </ul>
        <p
            v-if="peerMedianLift && Object.keys(peerMedianLift).length"
            class="text-[10px] text-slate-500"
        >
            Peer median lift:
            <span
                v-for="(lift, format) in peerMedianLift"
                :key="String(format)"
                class="mr-2 tabular-nums"
            >
                {{ format }} {{ lift != null ? `${Number(lift).toFixed(2)}×` : '—' }}
            </span>
        </p>
    </div>
</template>
