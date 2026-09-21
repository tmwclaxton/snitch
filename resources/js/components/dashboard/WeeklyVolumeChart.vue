<script setup lang="ts">
import { computed } from 'vue';
import StippleBar from '@/components/dashboard/StippleBar.vue';

export type WeeklyBucket = {
    week_start: string;
    label: string;
    count: number;
};

export type VolumePeriodGrain = 'day' | 'week' | 'month';

const props = withDefaults(
    defineProps<{
        weeks: WeeklyBucket[];
        title?: string;
        subtitle?: string;
        /** Widen slots and angle labels for month-style axes. */
        denseLabels?: boolean;
        yAxisLabel?: string;
        xAxisLabel?: string;
        periodGrain?: VolumePeriodGrain;
        compact?: boolean;
    }>(),
    {
        title: 'Weekly volume',
        subtitle: undefined,
        denseLabels: false,
        yAxisLabel: 'No. of Posts',
        xAxisLabel: undefined,
        periodGrain: undefined,
        compact: false,
    },
);

const resolvedXAxisLabel = computed(() => {
    if (props.xAxisLabel) {
        return props.xAxisLabel;
    }

    if (props.periodGrain === 'month') {
        return 'Month';
    }

    if (props.periodGrain === 'day') {
        return 'Day';
    }

    return 'Week starting';
});

const leftPad = computed(() => (props.compact ? 44 : 62));
const rightPad = 10;
const topPad = 10;
const plotHeight = computed(() => (props.compact ? 92 : 168));

const maxCount = computed(() =>
    Math.max(1, ...props.weeks.map((week) => week.count)),
);

const yScale = computed(() => {
    const max = maxCount.value;
    const steps = 4;
    const step = max <= 1 ? 1 : Math.max(1, Math.ceil(max / steps));
    const niceMax = step * steps;

    return { step, niceMax };
});

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

const tiltXLabels = computed(
    () => props.denseLabels || props.weeks.length > 8,
);

const slotWidth = computed(() => (props.denseLabels ? 48 : 40));
const maxBarWidth = 18;
const labelPad = computed(() => (tiltXLabels.value ? 54 : 44));
const plotWidth = computed(() =>
    Math.max(slotWidth.value, props.weeks.length * slotWidth.value),
);
const chartWidth = computed(() => leftPad.value + plotWidth.value + rightPad);
const chartHeight = computed(() => topPad + plotHeight.value + labelPad.value);
const barWidth = computed(() =>
    Math.min(maxBarWidth, Math.max(5, slotWidth.value - 14)),
);

const yTicks = computed(() => {
    const { step, niceMax } = yScale.value;
    const values: number[] = [];

    for (let value = 0; value <= niceMax; value += step) {
        values.push(value);
    }

    return values.map((value) => ({
        value,
        y: topPad + (1 - value / niceMax) * plotHeight.value,
    }));
});

const xLabelIndexes = computed(() => {
    const n = props.weeks.length;
    const every = props.denseLabels ? (n > 18 ? 2 : 1) : n > 14 ? 2 : 1;
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
    return (count / yScale.value.niceMax) * plotHeight.value;
}

function drawnHeight(count: number): number {
    return Math.max(barHeight(count), count > 0 ? 5 : 0);
}

function barX(index: number): number {
    return leftPad.value + index * slotWidth.value + (slotWidth.value - barWidth.value) / 2;
}

function barY(count: number): number {
    return topPad + plotHeight.value - drawnHeight(count);
}

function labelY(): number {
    return topPad + plotHeight.value + (tiltXLabels.value ? 20 : 16);
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
            :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
            role="img"
            :aria-label="`${title}, ${total} ${yAxisLabel.toLowerCase()} over ${weeks.length} periods`"
        >
            <text
                :x="13"
                :y="topPad + plotHeight / 2"
                text-anchor="middle"
                class="fill-snitch-ink/55"
                style="font-size: 10px"
                :transform="`rotate(-90 13 ${topPad + plotHeight / 2})`"
            >
                {{ yAxisLabel }}
            </text>
            <g v-for="tick in yTicks" :key="`y-${tick.value}`">
                <line
                    :x1="leftPad"
                    :x2="leftPad + plotWidth"
                    :y1="tick.y"
                    :y2="tick.y"
                    class="stroke-snitch-ink/15"
                    stroke-width="1"
                />
                <text
                    :x="leftPad - 6"
                    :y="tick.y + 3"
                    text-anchor="end"
                    class="fill-snitch-ink/50"
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
                    :step="2.5"
                    :radius="0.8"
                    :seed="index + 1"
                    :fill-class="
                        index === peakIndex
                            ? 'fill-snitch-stipple-spot'
                            : 'fill-snitch-ink/70'
                    "
                    :title="`${week.label}: ${week.count} ${yAxisLabel.toLowerCase()}`"
                />
            </g>
            <text
                v-for="index in xLabelIndexes"
                :key="`x-${weeks[index]!.week_start}`"
                :x="barX(index) + barWidth / 2"
                :y="labelY()"
                :text-anchor="tiltXLabels ? 'end' : 'middle'"
                class="fill-snitch-ink/50"
                :font-size="denseLabels ? 8 : 9"
                :transform="
                    tiltXLabels
                        ? `rotate(-32 ${barX(index) + barWidth / 2} ${labelY()})`
                        : undefined
                "
            >
                {{ weeks[index]!.label }}
            </text>
            <text
                :x="leftPad + plotWidth / 2"
                :y="topPad + plotHeight + labelPad - 4"
                text-anchor="middle"
                class="fill-snitch-ink/55"
                style="font-size: 10px"
            >
                {{ resolvedXAxisLabel }}
            </text>
        </svg>
    </div>
</template>
