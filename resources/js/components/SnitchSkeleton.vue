<script setup lang="ts">
import { computed } from 'vue';

type Variant = 'block' | 'line' | 'scrap' | 'polaroid';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        height?: string;
        width?: string;
        radius?: string;
        label?: string;
    }>(),
    {
        variant: 'block',
        height: '1rem',
        width: '100%',
        radius: '0',
        label: 'Loading',
    },
);

const style = computed(() => ({
    height: props.height,
    width: props.width,
    borderRadius: props.radius,
}));

const variantClass = computed(() => {
    switch (props.variant) {
        case 'line':
            return 'snitch-skeleton-line';
        case 'scrap':
            return 'snitch-skeleton-scrap';
        case 'polaroid':
            return 'snitch-skeleton-polaroid';
        default:
            return 'snitch-skeleton-block';
    }
});
</script>

<template>
    <div
        role="status"
        :aria-label="label"
        class="snitch-skeleton"
        :class="variantClass"
        :style="style"
    >
        <span class="sr-only">{{ label }}</span>
    </div>
</template>

<style scoped>
.snitch-skeleton {
    border: 1px solid color-mix(in oklab, var(--snitch-ink) 5%, transparent);
    background-color: color-mix(in oklab, var(--snitch-fog) 38%, var(--snitch-paper));
    background-image: linear-gradient(
        135deg,
        color-mix(in oklab, var(--snitch-ink) 2%, transparent) 20%,
        color-mix(in oklab, var(--snitch-ink) 6%, transparent) 50%,
        color-mix(in oklab, var(--snitch-ink) 2%, transparent) 80%
    );
    animation: snitch-skeleton-pulse 2.6s ease-in-out infinite;
}

@keyframes snitch-skeleton-pulse {
    0%,
    100% {
        opacity: 1;
    }

    50% {
        opacity: 0.9;
    }
}

.snitch-skeleton-line {
    height: 0.75rem;
}

.snitch-skeleton-polaroid {
    aspect-ratio: 3 / 4;
    height: auto;
}

.snitch-skeleton-scrap {
    min-height: 5rem;
}
</style>
