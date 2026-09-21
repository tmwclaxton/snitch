<script setup lang="ts">
import { computed } from 'vue';
import StippleBar from '@/components/dashboard/StippleBar.vue';

export type TimeOfDayBucket = {
    hour: number;
    label: string;
    count: number;
};

const props = defineProps<{
    hours: TimeOfDayBucket[];
}>();

const yAxisLabel = 'No. of Posts';
const xAxisLabel = 'Hour posted';

const leftPad = 62;
const rightPad = 10;
const topPad = 10;
const plotHeight = 168;
const plotWidth = 440;
const xLabelOffset = 18;
const xTitleOffset = 36;
const bottomPad = 50;
const chartWidth = leftPad + plotWidth + rightPad;
const chartHeight = topPad + plotHeight + bottomPad;
const barGap = 2.4;

const maxCount = computed(() =>
    Math.max(1, ...props.hours.map((row) => row.count)),
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

    props.hours.forEach((row, index) => {
        if (row.count > peakCount) {
            peakCount = row.count;
            peak = index;
        }
    });

    return peak;
});

const total = computed(() =>
    props.hours.reduce((sum, row) => sum + row.count, 0),
);

const barWidth = computed(() => {
    const n = Math.max(props.hours.length, 1);

    return (plotWidth - barGap * (n - 1)) / n;
});

const yTicks = computed(() => {
    const { step, niceMax } = yScale.value;
    const values: number[] = [];

    for (let value = 0; value <= niceMax; value += step) {
        values.push(value);
    }

    return values.map((value) => ({
        value,
        y: topPad + (1 - value / niceMax) * plotHeight,
    }));
});

function barHeight(count: number): number {
    return (count / yScale.value.niceMax) * plotHeight;
}

function drawnHeight(count: number): number {
    return Math.max(barHeight(count), count > 0 ? 5 : 0);
}

function barX(index: number): number {
    return leftPad + index * (barWidth.value + barGap);
}

function barY(count: number): number {
    return topPad + plotHeight - drawnHeight(count);
}

function showLabel(hour: number): boolean {
    return hour % 2 === 0;
}
</script>

<template>
    <div class="snitch-time-of-day">
        <div class="flex items-baseline justify-between gap-3">
            <p class="snitch-ink-label">Time of day</p>
            <p class="tabular-nums text-xs text-snitch-ink/55">
                {{ total }} posts · 12 wks
            </p>
        </div>

        <svg
            class="mt-3 w-full overflow-visible"
            :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
            role="img"
            :aria-label="`Snitch posts by hour posted, ${total} over 12 weeks`"
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
            <g
                v-for="(row, index) in hours"
                :key="row.hour"
            >
                <StippleBar
                    v-if="row.count > 0"
                    :x="barX(index)"
                    :y="barY(row.count)"
                    :width="barWidth"
                    :height="drawnHeight(row.count)"
                    variant="dots"
                    grow-from="bottom"
                    :delay-offset="index * 12"
                    :step-ms="18"
                    :step="2.5"
                    :radius="0.8"
                    :seed="row.hour + 11"
                    :fill-class="
                        index === peakIndex
                            ? 'fill-snitch-stipple-spot'
                            : 'fill-snitch-ink/70'
                    "
                    :title="`${row.label}: ${row.count} posts`"
                />
                <text
                    v-if="showLabel(row.hour)"
                    :x="barX(index) + barWidth / 2"
                    :y="topPad + plotHeight + xLabelOffset"
                    text-anchor="middle"
                    class="fill-snitch-ink/50"
                    style="font-size: 8px"
                >
                    {{ row.label }}
                </text>
            </g>
            <text
                :x="leftPad + plotWidth / 2"
                :y="topPad + plotHeight + xTitleOffset"
                text-anchor="middle"
                class="fill-snitch-ink/55"
                style="font-size: 10px"
            >
                {{ xAxisLabel }}
            </text>
        </svg>
    </div>
</template>
