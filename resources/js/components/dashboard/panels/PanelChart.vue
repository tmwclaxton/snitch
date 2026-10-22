<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import { connectedLineData } from '@/lib/lineChart';
import { snitchApexTheme, snitchIsDark } from '@/lib/snitchTheme';

const props = defineProps<{
    title: string;
    kind: 'line' | 'bar';
    payload: {
        y_label?: string | null;
        series?: Array<{
            name: string;
            points: Array<{ x: string | number; y: number | null }>;
        }>;
    };
}>();

const chartSeries = computed(() => {
    const rows = props.payload.series ?? [];

    if (props.kind === 'line') {
        return rows.map((row) => ({
            name: row.name,
            data: connectedLineData(
                (row.points ?? []).map((point) => ({
                    date: String(point.x),
                    value: point.y,
                })),
            ),
        }));
    }

    return rows.map((row) => ({
        name: row.name,
        data: (row.points ?? [])
            .filter((point) => point.y != null && Number.isFinite(Number(point.y)))
            .map((point) => ({
                x: String(point.x),
                y: Number(point.y),
            })),
    }));
});

const options = computed(() => {
    const dark = snitchIsDark();
    const label = dark ? '#EDEAE2' : '#1C1B1A';

    return {
        theme: snitchApexTheme(),
        chart: {
            toolbar: { show: false },
            zoom: { enabled: false },
            background: 'transparent',
        },
        stroke: props.kind === 'line'
            ? { curve: 'smooth' as const, width: 3 }
            : { width: 0 },
        dataLabels: { enabled: false },
        legend: {
            show: (props.payload.series?.length ?? 0) > 1,
            position: 'top' as const,
            fontSize: '14px',
            labels: { colors: label },
        },
        xaxis: {
            type: (props.kind === 'line' ? 'datetime' : 'category') as 'datetime' | 'category',
            labels: {
                style: { fontSize: '14px', colors: label },
            },
        },
        yaxis: {
            title: {
                text: props.payload.y_label || undefined,
                style: { fontSize: '14px', color: label },
            },
            labels: {
                style: { fontSize: '14px', colors: label },
            },
        },
        grid: {
            borderColor: dark ? 'rgba(255,255,255,0.1)' : 'rgba(28,27,26,0.1)',
        },
        plotOptions: props.kind === 'bar'
            ? { bar: { borderRadius: 0, columnWidth: '55%' } }
            : undefined,
        colors: dark
            ? ['#FCD700', '#FF3D8B', '#5B9BD5', '#6FBF73']
            : ['#1C1B1A', '#3A5F6B', '#C45C26', '#2F6B4F'],
    };
});
</script>

<template>
    <article class="rounded border border-snitch-ink/10 bg-snitch-lift p-4">
        <h2 class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
            {{ title }}
        </h2>
        <div class="mt-3 min-h-[12rem]">
            <VueApexCharts
                :type="kind"
                height="220"
                :options="options"
                :series="chartSeries"
            />
        </div>
    </article>
</template>
