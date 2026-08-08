<script setup lang="ts">
import { computed } from 'vue';

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

const chartHeight = 100;
const barGap = 3;
const chartWidth = 360;
const barWidth = computed(() => {
    const n = Math.max(props.hours.length, 1);

    return (chartWidth - barGap * (n - 1)) / n;
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
            <line
                :x1="0"
                :y1="chartHeight"
                :x2="chartWidth"
                :y2="chartHeight"
                class="stroke-snitch-ink/15"
                stroke-width="1"
            />
            <g
                v-for="(row, index) in hours"
                :key="row.hour"
            >
                <rect
                    :x="barX(index)"
                    :y="barY(row.count)"
                    :width="barWidth"
                    :height="Math.max(barHeight(row.count), row.count > 0 ? 3 : 0)"
                    :class="
                        index === peakIndex && row.count > 0
                            ? 'fill-snitch-spot'
                            : 'fill-snitch-ink/70'
                    "
                    rx="1"
                >
                    <title>{{ row.label }}: {{ row.count }}</title>
                </rect>
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
