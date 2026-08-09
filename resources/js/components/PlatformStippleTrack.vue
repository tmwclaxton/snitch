<script setup lang="ts">
import { computed } from 'vue';
import StippleBar from '@/components/dashboard/StippleBar.vue';

const props = withDefaults(
    defineProps<{
        count: number;
        maxCount: number;
        isPeak?: boolean;
        seed?: number;
        title?: string;
        delayOffset?: number;
    }>(),
    {
        isPeak: false,
        seed: 0,
        delayOffset: 0,
    },
);

/** Design width for mark geometry so dots stay round in a responsive track. */
const trackUnits = 240;
const trackHeight = 10;

const fillWidth = computed(() =>
    Math.max((props.count / Math.max(props.maxCount, 1)) * trackUnits, props.count > 0 ? 8 : 0),
);

const fillPercent = computed(() => (fillWidth.value / trackUnits) * 100);
</script>

<template>
    <div
        class="snitch-platform-bar-track"
        :title="title"
    >
        <svg
            v-if="count > 0"
            class="snitch-platform-bar-stipple"
            :style="{ width: `${fillPercent}%` }"
            :viewBox="`0 0 ${fillWidth} ${trackHeight}`"
            preserveAspectRatio="xMinYMid meet"
            aria-hidden="true"
        >
            <StippleBar
                :x="0"
                :y="0"
                :width="fillWidth"
                :height="trackHeight"
                variant="dots"
                grow-from="left"
                :delay-offset="delayOffset"
                :step-ms="22"
                :step="4.6"
                :radius="1.55"
                :seed="seed"
                :fill-class="
                    isPeak
                        ? 'fill-snitch-spot'
                        : 'fill-snitch-ink/70'
                "
            />
        </svg>
    </div>
</template>
