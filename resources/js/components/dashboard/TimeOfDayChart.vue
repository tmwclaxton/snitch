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

const maxCount = computed(() =>
    Math.max(1, ...props.hours.map((row) => row.count)),
);

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

const leftPad = 28;
const chartHeight = 100;
const plotTop = 8;
const barGap = 3;
const plotWidth = 360;
const chartWidth = leftPad + plotWidth;
const barWidth = computed(() => {
    const n = Math.max(props.hours.length, 1);

    return (plotWidth - barGap * (n - 1)) / n;
});

const yTicks = computed(() => {
    const max = maxCount.value;
    const values =
        max <= 1 ? [0, 1] : max <= 3 ? [0, max] : [0, Math.round(max / 2), max];

    return values.map((value) => ({
        value,
        y: plotTop + (1 - value / max) * (chartHeight - plotTop),
    }));
});

function barHeight(count: number): number {
    return (count / maxCount.value) * (chartHeight - plotTop);
}

function drawnHeight(count: number): number {
    return Math.max(barHeight(count), count > 0 ? 4 : 0);
}

function barX(index: number): number {
    return leftPad + index * (barWidth.value + barGap);
}

function barY(count: number): number {
    return chartHeight - drawnHeight(count);
}

function showLabel(hour: number): boolean {
    return hour % 3 === 0;
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
            :viewBox="`0 0 ${chartWidth} ${chartHeight + 24}`"
            role="img"
            :aria-label="`Competitor posts by hour, ${total} over 12 weeks`"
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
                    :step="3.1"
                    :radius="0.95"
                    :seed="row.hour + 11"
                    :fill-class="
                        index === peakIndex
                            ? 'fill-snitch-spot'
                            : 'fill-snitch-ink/70'
                    "
                    :title="`${row.label}: ${row.count}`"
                />
                <text
                    v-if="showLabel(row.hour)"
                    :x="barX(index) + barWidth / 2"
                    :y="chartHeight + 16"
                    text-anchor="middle"
                    class="fill-snitch-ink/45"
                    style="font-size: 9px"
                >
                    {{ row.label }}
                </text>
            </g>
        </svg>
    </div>
</template>
