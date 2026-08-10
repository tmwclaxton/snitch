<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import SnitchImage from '@/components/SnitchImage.vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        src?: string | null;
        /** Display name preferred for initials */
        name?: string | null;
        /** Handle used when name is empty */
        handle?: string | null;
        size?: 'sm' | 'md' | 'lg' | 'xl';
        loading?: 'lazy' | 'eager';
        alt?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        size: 'sm',
        loading: 'lazy',
        alt: '',
    },
);

const sizeClass = {
    sm: 'size-8 text-xs',
    md: 'size-10 text-sm',
    lg: 'size-12 text-sm',
    xl: 'size-16 text-lg sm:size-20 sm:text-xl',
} as const;

const initialsFrom = computed(() => props.name?.trim() || props.handle?.trim() || null);

const pixelSize = computed(() => {
    switch (props.size) {
        case 'md':
            return 40;
        case 'lg':
            return 48;
        case 'xl':
            return 80;
        default:
            return 32;
    }
});
</script>

<template>
    <SnitchImage
        :src="src"
        :alt="alt"
        :width="pixelSize"
        :height="pixelSize"
        aspect-ratio="1 / 1"
        :loading="loading"
        decoding="async"
        fallback="initials"
        :initials-from="initialsFrom"
        :class="cn('snitch-avatar', sizeClass[size], props.class)"
        img-class="size-full object-cover"
    />
</template>
