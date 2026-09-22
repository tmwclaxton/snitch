<script setup lang="ts">
import { computed } from 'vue';
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
        /** Reveal marks with a CSS stagger from the bar origin on mount. */
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

const prefersReducedMotion = (): boolean => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return false;
    }

    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
};

const shouldAnimate = computed(
    () => props.animate && !prefersReducedMotion(),
);

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

    if (!shouldAnimate.value || built.length <= 1) {
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

function delayStyle(index: number): Record<string, string> | undefined {
    if (!shouldAnimate.value) {
        return undefined;
    }

    return {
        animationDelay: `${props.delayOffset + index * props.stepMs}ms`,
    };
}
</script>

<template>
    <g class="snitch-stipple-bar" :class="fillClass">
        <title v-if="title">{{ title }}</title>
        <template v-for="(mark, index) in marks" :key="index">
            <circle
                v-if="mark.kind === 'circle'"
                class="snitch-stipple-mark"
                :class="{ 'is-popping': shouldAnimate }"
                :style="delayStyle(index)"
                :cx="mark.cx"
                :cy="mark.cy"
                :r="mark.r"
            />
            <polygon
                v-else-if="mark.kind === 'hex'"
                class="snitch-stipple-mark"
                :class="{ 'is-popping': shouldAnimate }"
                :style="delayStyle(index)"
                :points="mark.points"
            />
        </template>
    </g>
</template>
