<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { useBrokenImage } from '@/composables/useBrokenImage';
import { getInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';

export type SnitchImageFallback = 'paper' | 'polaroid' | 'initials' | 'none';

defineOptions({
    inheritAttrs: false,
});

const props = withDefaults(
    defineProps<{
        src?: string | null;
        alt?: string;
        width?: number | string;
        height?: number | string;
        /** CSS aspect-ratio value, e.g. "1 / 1" or "3 / 4" */
        aspectRatio?: string;
        loading?: 'lazy' | 'eager';
        decoding?: 'async' | 'sync' | 'auto';
        /** Reserved fallback when src is missing or fails to load */
        fallback?: SnitchImageFallback;
        /** Label used for initials fallback (name or handle) */
        initialsFrom?: string | null;
        imgClass?: HTMLAttributes['class'];
        class?: HTMLAttributes['class'];
    }>(),
    {
        alt: '',
        loading: 'lazy',
        decoding: 'async',
        fallback: 'paper',
    },
);

const { showImage, onError } = useBrokenImage(() => props.src);

const initials = computed(() => {
    const from = props.initialsFrom?.trim();

    if (!from) {
        return '?';
    }

    const bare = from.replace(/^@/, '');

    if (!bare.includes(' ')) {
        return bare.slice(0, 2).toUpperCase() || '?';
    }

    return getInitials(bare) || '?';
});

const wrapperStyle = computed(() => {
    if (!props.aspectRatio) {
        return undefined;
    }

    // Width/height stay on the <img> as intrinsic hints; size utilities on
    // `class` own the laid-out box so responsive avatar sizes still work.
    return { aspectRatio: props.aspectRatio };
});

const showFallback = computed(() => !showImage.value && props.fallback !== 'none');
</script>

<template>
    <span
        class="snitch-image"
        :class="cn(props.class)"
        :style="wrapperStyle"
        :data-fallback="showFallback ? fallback : undefined"
    >
        <img
            v-if="showImage"
            :src="src!"
            :alt="alt"
            :width="width"
            :height="height"
            :loading="loading"
            :decoding="decoding"
            :class="cn('snitch-image-img', imgClass)"
            v-bind="$attrs"
            @error="onError"
        >
        <span
            v-else-if="fallback === 'initials'"
            class="snitch-image-fallback snitch-image-fallback-initials"
            aria-hidden="true"
        >
            {{ initials }}
        </span>
        <span
            v-else-if="fallback === 'polaroid'"
            class="snitch-image-fallback snitch-image-fallback-polaroid"
            aria-hidden="true"
        >
            <img
                src="/images/marketing/empty-404.jpg"
                alt=""
                class="snitch-image-fallback-polaroid-img"
                width="640"
                height="400"
                loading="lazy"
                decoding="async"
            >
        </span>
        <span
            v-else-if="fallback === 'paper'"
            class="snitch-image-fallback snitch-image-fallback-paper"
            aria-hidden="true"
        />
    </span>
</template>
