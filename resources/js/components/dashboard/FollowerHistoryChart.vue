<script setup lang="ts">
import { computed } from 'vue';
import { formatFollowers } from '@/lib/metrics';

export type FollowerPoint = {
    captured_on: string;
    label: string;
    followers: number;
};

const props = withDefaults(
    defineProps<{
        points: FollowerPoint[];
        scope?: 'account' | 'corpus';
        hideTitle?: boolean;
        compact?: boolean;
    }>(),
    {
        hideTitle: false,
        compact: false,
    },
);

const scopeLabel = computed(() =>
    props.scope === 'corpus'
        ? 'Across your tracked accounts.'
        : 'Recorded while this account is tracked.',
);

const leftPad = 54;
const rightPad = 12;
const topPad = 12;
const bottomPad = 24;
const plotWidth = 560;
const plotHeight = computed(() => (props.compact ? 96 : 140));
const chartWidth = leftPad + plotWidth + rightPad;
const chartHeight = computed(() => topPad + plotHeight.value + bottomPad);

const minFollowers = computed(() =>
    props.points.length ? Math.min(...props.points.map((point) => point.followers)) : 0,
);

const maxFollowers = computed(() =>
    props.points.length ? Math.max(...props.points.map((point) => point.followers)) : 0,
);

function yFor(followers: number): number {
    const min = minFollowers.value;
    const max = maxFollowers.value;
    const span = Math.max(1, max - min);
    const paddedMin = min === max ? min - span : min;
    const paddedMax = min === max ? max + span : max;
    const range = paddedMax - paddedMin;

    return topPad + (1 - (followers - paddedMin) / range) * plotHeight.value;
}

const dots = computed(() =>
    props.points.map((point, index) => {
        const x = props.points.length === 1
            ? leftPad + plotWidth / 2
            : leftPad + (index / (props.points.length - 1)) * plotWidth;

        return {
            ...point,
            x,
            y: yFor(point.followers),
        };
    }),
);

const linePoints = computed(() =>
    dots.value.map((dot) => `${dot.x},${dot.y}`).join(' '),
);

const yTicks = computed(() => {
    const min = minFollowers.value;
    const max = maxFollowers.value;

    return [
        { value: max, y: yFor(max) },
        { value: min === max ? min : Math.round((min + max) / 2), y: yFor(min === max ? min : (min + max) / 2) },
        { value: min, y: yFor(min) },
    ].filter((tick, index, ticks) => ticks.findIndex((row) => row.value === tick.value) === index);
});
</script>

<template>
    <div class="flex h-full flex-col">
        <div v-if="!hideTitle" class="flex items-baseline justify-between gap-3">
            <p class="snitch-ink-label">Followers</p>
            <p class="text-xs text-snitch-ink/55">
                {{ points.length === 1 ? 'First recorded count' : `${points.length} counts` }}
            </p>
        </div>
        <p v-if="!hideTitle" class="mt-1 text-xs text-snitch-ink/55">
            {{ scopeLabel }}
        </p>
        <svg
            v-if="points.length"
            class="mt-1 max-h-40 w-full overflow-visible"
            :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
            role="img"
            :aria-label="`Follower counts, ${points.length} points`"
        >
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
                    style="font-size: 10px"
                >
                    {{ formatFollowers(tick.value) }}
                </text>
            </g>
            <polyline
                v-if="dots.length > 1"
                :points="linePoints"
                fill="none"
                class="stroke-snitch-ink"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
            <g v-for="dot in dots" :key="dot.captured_on">
                <circle
                    :cx="dot.x"
                    :cy="dot.y"
                    r="4"
                    class="fill-snitch-spot stroke-snitch-ink"
                    stroke-width="1"
                />
                <text
                    :x="dot.x"
                    :y="chartHeight - 6"
                    text-anchor="middle"
                    class="fill-snitch-ink/55"
                    style="font-size: 10px"
                >
                    {{ dot.label }}
                </text>
            </g>
        </svg>
        <p v-else class="mt-3 text-sm text-snitch-ink/60">
            No follower counts yet.
        </p>
    </div>
</template>
