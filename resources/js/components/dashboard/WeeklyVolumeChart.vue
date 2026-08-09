<script setup lang="ts">
import { computed } from 'vue';
import StippleBar from '@/components/dashboard/StippleBar.vue';

export type WeeklyBucket = {
    week_start: string;
    label: string;
    count: number;
};

const props = defineProps<{
    weeks: WeeklyBucket[];
}>();

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
const barGap = 6;
const plotWidth = computed(() => Math.max(240, props.weeks.length * 28));
const chartWidth = computed(() => leftPad + plotWidth.value);
const barWidth = computed(() => {
    const n = Math.max(props.weeks.length, 1);

    return (plotWidth.value - barGap * (n - 1)) / n;
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
</script>

<template>
    <div class="snitch-volume-chart">
        <div class="flex items-baseline justify-between gap-3">
            <p class="snitch-ink-label">Weekly volume</p>
            <p class="tabular-nums text-xs text-snitch-ink/55">
                {{ total }} posts · 12 wks
            </p>
        </div>

        <svg
            class="mt-3 w-full overflow-visible"
            :viewBox="`0 0 ${chartWidth} ${chartHeight + 28}`"
            role="img"
            :aria-label="`Weekly competitor posts, ${total} over 12 weeks`"
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
                            ? 'fill-snitch-spot'
                            : 'fill-snitch-ink/70'
                    "
                    :title="`${week.label}: ${week.count}`"
                />
                <text
                    v-if="index % 2 === 0 || index === weeks.length - 1"
                    :x="barX(index) + barWidth / 2"
                    :y="chartHeight + 16"
                    text-anchor="middle"
                    class="fill-snitch-ink/45"
                    style="font-size: 9px"
                >
                    {{ week.label }}
                </text>
            </g>
        </svg>
    </div>
</template>
