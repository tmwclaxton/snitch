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
        class="snitch-skeleton animate-pulse"
        :class="variantClass"
        :style="style"
    >
        <span class="sr-only">{{ label }}</span>
    </div>
</template>

<style scoped>
.snitch-skeleton {
    background: color-mix(in oklab, var(--snitch-ink, #1c1b1a) 10%, transparent);
    background-image: linear-gradient(
        135deg,
        color-mix(in oklab, var(--snitch-ink, #1c1b1a) 4%, transparent) 25%,
        color-mix(in oklab, var(--snitch-ink, #1c1b1a) 12%, transparent) 50%,
        color-mix(in oklab, var(--snitch-ink, #1c1b1a) 4%, transparent) 75%
    );
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
