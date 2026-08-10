<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { buildStippleMarks } from '@/lib/stipple';
import type { StippleMark, StippleVariant } from '@/lib/stipple';

export type StippleGrowFrom = 'bottom' | 'left';

const props = withDefaults(
    defineProps<{
        x: number;
        y: number;
        width: number;
        height: number;
        variant?: StippleVariant;
        fillClass?: string;
        step?: number;
        radius?: number;
        seed?: number;
        title?: string;
        /** Reveal marks one at a time from the bar origin on mount. */
        animate?: boolean;
        growFrom?: StippleGrowFrom;
        /** Extra ms before the first mark appears (bar stagger). */
        delayOffset?: number;
        /** Gap between consecutive mark reveals. */
        stepMs?: number;
    }>(),
    {
        variant: 'dots',
        fillClass: 'fill-snitch-ink/70',
        seed: 0,
        animate: true,
        growFrom: 'bottom',
        delayOffset: 0,
        stepMs: 20,
    },
);

const visibleCount = ref(0);
const timers: number[] = [];

const prefersReducedMotion = (): boolean => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return false;
    }

    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
};

const marks = computed(() => {
    const built = buildStippleMarks({
        x: props.x,
        y: props.y,
        width: props.width,
        height: props.height,
        variant: props.variant,
        step: props.step,
        radius: props.radius,
        seed: props.seed,
    });

    if (!props.animate || built.length <= 1) {
        return built;
    }

    return [...built].sort((a, b) => compareMarks(a, b, props.growFrom));
});

function compareMarks(a: StippleMark, b: StippleMark, growFrom: StippleGrowFrom): number {
    const aa = markAnchor(a);
    const bb = markAnchor(b);

    if (growFrom === 'left') {
        return aa.x - bb.x || aa.y - bb.y;
    }

    // Bottom first (higher cy last in SVG coords), then left to right.
    return bb.y - aa.y || aa.x - bb.x;
}

function markAnchor(mark: StippleMark): { x: number; y: number } {
    if (mark.kind === 'circle') {
        return { x: mark.cx, y: mark.cy };
    }

    const points = mark.points.split(' ').map((pair) => {
        const [px, py] = pair.split(',').map(Number);

        return { x: px, y: py };
    });

    const count = Math.max(points.length, 1);

    return {
        x: points.reduce((sum, point) => sum + point.x, 0) / count,
        y: points.reduce((sum, point) => sum + point.y, 0) / count,
    };
}

function clearTimers(): void {
    while (timers.length > 0) {
        const id = timers.pop();

        if (id !== undefined) {
            window.clearTimeout(id);
        }
    }
}

function startReveal(): void {
    clearTimers();

    const total = marks.value.length;

    if (!props.animate || total === 0 || prefersReducedMotion()) {
        visibleCount.value = total;

        return;
    }

    visibleCount.value = 0;

    for (let index = 0; index < total; index += 1) {
        const delay = props.delayOffset + index * props.stepMs;
        const id = window.setTimeout(() => {
            visibleCount.value = index + 1;
        }, delay);

        timers.push(id);
    }
}

onMounted(() => {
    startReveal();
});

watch(
    () =>
        [
            props.x,
            props.y,
            props.width,
            props.height,
            props.seed,
            props.step,
            props.radius,
            props.variant,
            props.delayOffset,
            props.stepMs,
            marks.value.length,
        ] as const,
    () => {
        startReveal();
    },
);

onBeforeUnmount(() => {
    clearTimers();
});
</script>

<template>
    <g class="snitch-stipple-bar" :class="fillClass">
        <title v-if="title">{{ title }}</title>
        <template v-for="(mark, index) in marks" :key="index">
            <circle
                v-if="mark.kind === 'circle' && index < visibleCount"
                class="snitch-stipple-mark is-popping"
                :cx="mark.cx"
                :cy="mark.cy"
                :r="mark.r"
            />
            <polygon
                v-else-if="mark.kind === 'hex' && index < visibleCount"
                class="snitch-stipple-mark is-popping"
                :points="mark.points"
            />
        </template>
    </g>
</template>
