<script setup lang="ts">
withDefaults(
    defineProps<{
        /** Logo mark height utility */
        size?: 'nav' | 'footer' | 'sidebar';
        /** When false, renders icon only (collapsed / icon slots) */
        wordmark?: boolean;
    }>(),
    {
        size: 'nav',
        wordmark: true,
    },
);

const imgClass = {
    nav: 'size-7 sm:size-8',
    footer: 'size-7',
    sidebar: 'size-8',
} as const;

const textClass = {
    nav: 'text-2xl',
    footer: 'text-xl',
    sidebar: 'text-base',
} as const;
</script>

<template>
    <span class="inline-flex min-w-0 items-center gap-2">
        <img
            src="/images/brand/mascot-mark.png"
            alt=""
            width="128"
            height="128"
            class="shrink-0 object-contain"
            :class="imgClass[size]"
            decoding="async"
        />
        <span
            v-if="wordmark"
            class="snitch-display relative truncate font-semibold tracking-tight text-snitch-ink"
            :class="textClass[size]"
        >
            <span
                class="pointer-events-none absolute inset-0 translate-x-[1.5px] translate-y-[1px] text-snitch-spot opacity-60 mix-blend-multiply select-none dark:mix-blend-plus-lighter dark:opacity-70"
                aria-hidden="true"
            >Snitch</span>
            <span class="relative">Snitch</span>
        </span>
    </span>
</template>
