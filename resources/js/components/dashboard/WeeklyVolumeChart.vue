<script setup lang="ts">
import { computed } from 'vue';

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

const chartHeight = 120;
const barGap = 6;
const chartWidth = computed(() => Math.max(240, props.weeks.length * 28));
const barWidth = computed(() => {
    const n = Math.max(props.weeks.length, 1);

    return (chartWidth.value - barGap * (n - 1)) / n;
});

function barHeight(count: number): number {
    return (count / maxCount.value) * (chartHeight - 8);
}

function barX(index: number): number {
    return index * (barWidth.value + barGap);
}

function barY(count: number): number {
    return chartHeight - barHeight(count);
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
            <line
                :x1="0"
                :y1="chartHeight"
                :x2="chartWidth"
                :y2="chartHeight"
                class="stroke-snitch-ink/15"
                stroke-width="1"
            />
            <g v-for="(week, index) in weeks" :key="week.week_start">
                <rect
                    :x="barX(index)"
                    :y="barY(week.count)"
                    :width="barWidth"
                    :height="Math.max(barHeight(week.count), week.count > 0 ? 3 : 0)"
                    :class="
                        index === peakIndex && week.count > 0
                            ? 'fill-snitch-spot'
                            : 'fill-snitch-ink/70'
                    "
                    rx="1"
                >
                    <title>{{ week.label }}: {{ week.count }}</title>
                </rect>
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
