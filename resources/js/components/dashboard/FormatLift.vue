<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import { useAccountColours } from '@/composables/useAccountColours';
import { snitchApexTheme, snitchAxisLabel, snitchAxisMuted, snitchInk, snitchPeerSeries } from '@/lib/snitchTheme';

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

const { colourFor } = useAccountColours();

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

const colours = computed(() =>
    (props.rows ?? []).map((row) => colourFor(row.handle, row.is_own_account)),
);

const options = computed(() => ({
    chart: { type: 'bar' as const, height: 180, toolbar: { show: false }, fontFamily: 'inherit', background: 'transparent' },
    theme: snitchApexTheme(),
    colors: colours.value,
    plotOptions: { bar: { columnWidth: '55%' } },
    dataLabels: { enabled: false },
    xaxis: {
        categories: formats.value,
        labels: { style: { colors: snitchAxisLabel(), fontSize: '14px' } },
    },
    yaxis: {
        title: { text: '× usual', style: { color: snitchAxisLabel(), fontSize: '14px' } },
        labels: { style: { colors: snitchAxisLabel(), fontSize: '14px' } },
    },
    annotations: {
        yaxis: [{
            y: 1,
            borderColor: snitchPeerSeries(),
            strokeDashArray: 4,
            label: { text: '1.0×', style: { fontSize: '12px', color: snitchAxisLabel(), background: 'transparent' } },
        }],
    },
    legend: { fontSize: '14px', labels: { colors: snitchInk('#141414') } },
    grid: { borderColor: snitchAxisMuted() },
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
                class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-snitch-ink/70"
            >
                <span class="font-medium text-snitch-ink">{{ row.is_own_account ? 'You' : `@${row.handle}` }}</span>
                <span
                    v-for="format in formats"
                    :key="`${row.handle}-${format}`"
                    class="tabular-nums"
                >
                    {{ format }}
                    <template v-if="row.lifts[format]">
                        {{ row.lifts[format].lift.toFixed(2) }}×
                        <span class="text-snitch-ink/45">(from {{ row.lifts[format].n }} posts)</span>
                    </template>
                    <template v-else>-</template>
                </span>
            </li>
        </ul>
        <p
            v-if="peerMedianLift && Object.keys(peerMedianLift).length"
            class="text-xs text-snitch-ink/55"
        >
            Rivals' average lift:
            <span
                v-for="(lift, format) in peerMedianLift"
                :key="String(format)"
                class="mr-2 tabular-nums"
            >
                {{ format }} {{ lift != null ? `${Number(lift).toFixed(2)}×` : '-' }}
            </span>
        </p>
    </div>
</template>
