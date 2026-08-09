<script setup lang="ts">
import { computed } from 'vue';
import StippleBar from '@/components/dashboard/StippleBar.vue';

export interface DailyMetricPoint {
    date: string;
    count: number;
}

const props = withDefaults(
    defineProps<{
        title: string;
        description: string;
        emptyTitle: string;
        emptyDescription: string;
        series: DailyMetricPoint[];
        days: number;
        barClass?: string;
        unitLabel?: string;
    }>(),
    {
        barClass: 'fill-snitch-ink/85 hover:fill-snitch-ink',
        unitLabel: 'events',
    },
);

const numberFormatter = new Intl.NumberFormat('en-GB');

function formatNumber(value: number): string {
    return numberFormatter.format(value);
}

function formatDate(value: string): string {
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-GB', {
        month: 'short',
        day: 'numeric',
    });
}

const maxCount = computed(() =>
    Math.max(1, ...props.series.map((point) => point.count)),
);

const chartWidth = 960;
const chartHeight = 280;
const padding = { top: 16, right: 12, bottom: 36, left: 48 };
const plotWidth = chartWidth - padding.left - padding.right;
const plotHeight = chartHeight - padding.top - padding.bottom;

const barWidth = computed(() => plotWidth / Math.max(props.series.length, 1));

const stippleStep = computed(() => (barWidth.value < 14 ? 3.4 : 4.4));
const stippleRadius = computed(() => (barWidth.value < 14 ? 1.0 : 1.35));

const bars = computed(() =>
    props.series.flatMap((point, index) => {
        if (point.count <= 0) {
            return [];
        }

        const rawHeight = (point.count / maxCount.value) * plotHeight;
        const height = Math.max(rawHeight, 4);
        const x = padding.left + index * barWidth.value + barWidth.value * 0.15;
        const width = barWidth.value * 0.7;
        const y = padding.top + plotHeight - height;

        return [
            {
                ...point,
                index,
                x,
                y,
                width,
                height,
            },
        ];
    }),
);

const yTicks = computed(() => {
    const steps = 4;
    const stepValue = Math.ceil(maxCount.value / steps);

    return Array.from({ length: steps + 1 }, (_, index) => {
        const value = stepValue * index;
        const y =
            padding.top + plotHeight - (value / maxCount.value) * plotHeight;

        return { value, y };
    });
});

const xLabels = computed(() => {
    const labelEvery = props.days <= 14 ? 2 : props.days <= 31 ? 5 : 10;

    return props.series
        .map((point, index) => ({ ...point, index }))
        .filter(
            (point) =>
                point.index === 0 ||
                point.index === props.series.length - 1 ||
                point.index % labelEvery === 0,
        );
});

const isEmpty = computed(() =>
    props.series.every((point) => point.count === 0),
);
</script>

<template>
    <div class="snitch-scrap overflow-hidden p-4 sm:p-6">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="snitch-ink-label">{{ title }}</h2>
                <p class="mt-1 text-sm text-snitch-ink/70">
                    {{ description }}
                </p>
            </div>
        </div>

        <div
            v-if="isEmpty"
            class="border border-dashed border-snitch-ink/25 bg-snitch-paper/40 px-6 py-16 text-center"
        >
            <p class="text-base font-semibold text-snitch-ink">
                {{ emptyTitle }}
            </p>
            <p class="mt-2 text-sm text-snitch-ink/65">
                {{ emptyDescription }}
            </p>
        </div>

        <div v-else class="overflow-x-auto">
            <svg
                :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
                class="h-auto w-full min-w-[640px]"
                role="img"
                :aria-label="`${title} bar chart`"
            >
                <g v-for="tick in yTicks" :key="tick.value">
                    <line
                        :x1="padding.left"
                        :x2="chartWidth - padding.right"
                        :y1="tick.y"
                        :y2="tick.y"
                        class="stroke-snitch-ink/15"
                        stroke-width="1"
                    />
                    <text
                        :x="padding.left - 8"
                        :y="tick.y + 4"
                        text-anchor="end"
                        class="fill-snitch-ink/55 text-[11px]"
                    >
                        {{ formatNumber(tick.value) }}
                    </text>
                </g>

                <StippleBar
                    v-for="bar in bars"
                    :key="bar.date"
                    :x="bar.x"
                    :y="bar.y"
                    :width="bar.width"
                    :height="bar.height"
                    variant="dots"
                    grow-from="bottom"
                    :delay-offset="bar.index * 10"
                    :step-ms="16"
                    :step="stippleStep"
                    :radius="stippleRadius"
                    :seed="bar.index + 31"
                    :fill-class="barClass"
                    :title="`${formatDate(bar.date)}: ${formatNumber(bar.count)} ${unitLabel}`"
                />

                <text
                    v-for="label in xLabels"
                    :key="label.date"
                    :x="padding.left + label.index * barWidth + barWidth / 2"
                    :y="chartHeight - 10"
                    text-anchor="middle"
                    class="fill-snitch-ink/55 text-[11px]"
                >
                    {{ formatDate(label.date) }}
                </text>
            </svg>
        </div>
    </div>
</template>
