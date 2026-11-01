<script setup lang="ts">
import { computed } from 'vue';
import StippleBar from '@/components/dashboard/StippleBar.vue';

export type WeeklyBucket = {
    week_start: string;
    label: string;
    count: number;
};

const props = withDefaults(
    defineProps<{
        weeks: WeeklyBucket[];
        title?: string;
        subtitle?: string;
        /** Widen slots and angle labels for month-style axes. */
        denseLabels?: boolean;
    }>(),
    {
        title: 'Weekly volume',
        subtitle: undefined,
        denseLabels: false,
    },
);

const maxCount = computed(() =>
    Math.max(1, ...props.weeks.map((week) => week.count)),
);

const peakIndex = computed(() => {
    let peak = 0;
    let peakCount = -1;

    props.weeks.forEach((week, index) => {
        if (week.count > peakCount) {
            peakCount = week.count;
            peak = index;
        }
    });

    return peak;
});

const total = computed(() =>
    props.weeks.reduce((sum, week) => sum + week.count, 0),
);

const leftPad = 28;
const chartHeight = 120;
const plotTop = 8;
const slotWidth = computed(() => (props.denseLabels ? 48 : 32));
const maxBarWidth = 16;
const labelPad = computed(() => (props.denseLabels ? 40 : 28));
const plotWidth = computed(() =>
    Math.max(slotWidth.value, props.weeks.length * slotWidth.value),
);
const chartWidth = computed(() => leftPad + plotWidth.value);
const barWidth = computed(() =>
    Math.min(maxBarWidth, Math.max(4, slotWidth.value - 12)),
);

const yTicks = computed(() => {
    const max = maxCount.value;
    const values =
        max <= 1 ? [0, 1] : max <= 3 ? [0, max] : [0, Math.round(max / 2), max];

    return values.map((value) => ({
        value,
        y: plotTop + (1 - value / max) * (chartHeight - plotTop),
    }));
});

const xLabelIndexes = computed(() => {
    const n = props.weeks.length;
    const every = props.denseLabels ? (n > 18 ? 2 : 1) : 2;
    const indexes: number[] = [];

    for (let index = 0; index < n; index++) {
        if (index === 0 || index === n - 1 || index % every === 0) {
            indexes.push(index);
        }
    }

    return indexes;
});

const summaryLabel = computed(() => {
    if (props.subtitle) {
        return props.subtitle;
    }

    return `${total.value} · ${props.weeks.length} buckets`;
});

function barHeight(count: number): number {
    return (count / maxCount.value) * (chartHeight - plotTop);
}

function drawnHeight(count: number): number {
    return Math.max(barHeight(count), count > 0 ? 4 : 0);
}

function barX(index: number): number {
    return leftPad + index * slotWidth.value + (slotWidth.value - barWidth.value) / 2;
}

function barY(count: number): number {
    return chartHeight - drawnHeight(count);
}
</script>

<template>
    <div class="snitch-volume-chart">
        <div class="flex items-baseline justify-between gap-3">
            <p class="snitch-ink-label">{{ title }}</p>
            <p class="tabular-nums text-xs text-snitch-ink/55">
                {{ summaryLabel }}
            </p>
        </div>

        <svg
            class="mt-3 w-full overflow-visible"
            :viewBox="`0 0 ${chartWidth} ${chartHeight + labelPad}`"
            role="img"
            :aria-label="`${title}, ${total} over ${weeks.length} periods`"
        >
            <g v-for="tick in yTicks" :key="`y-${tick.value}`">
                <line
                    :x1="leftPad"
                    :x2="chartWidth"
                    :y1="tick.y"
                    :y2="tick.y"
                    class="stroke-snitch-ink/15"
                    stroke-width="1"
                />
                <text
                    :x="leftPad - 6"
                    :y="tick.y + 3"
                    text-anchor="end"
                    class="fill-snitch-ink/45"
                    style="font-size: 9px"
                >
                    {{ tick.value }}
                </text>
            </g>
            <g v-for="(week, index) in weeks" :key="week.week_start">
                <StippleBar
                    v-if="week.count > 0"
                    :x="barX(index)"
                    :y="barY(week.count)"
                    :width="barWidth"
                    :height="drawnHeight(week.count)"
                    variant="dots"
                    grow-from="bottom"
                    :delay-offset="index * 24"
                    :step-ms="22"
                    :seed="index + 1"
                    :fill-class="
                        index === peakIndex
                            ? 'fill-snitch-stipple-spot'
                            : 'fill-snitch-ink/70'
                    "
                    :title="`${week.label}: ${week.count}`"
                />
            </g>
            <text
                v-for="index in xLabelIndexes"
                :key="`x-${weeks[index]!.week_start}`"
                :x="barX(index) + barWidth / 2"
                :y="chartHeight + (denseLabels ? 22 : 16)"
                :text-anchor="denseLabels ? 'end' : 'middle'"
                class="fill-snitch-ink/45"
                :font-size="denseLabels ? 8 : 9"
                :transform="
                    denseLabels
                        ? `rotate(-32 ${barX(index) + barWidth / 2} ${chartHeight + 22})`
                        : undefined
                "
            >
                {{ weeks[index]!.label }}
            </text>
        </svg>
    </div>
</template>
