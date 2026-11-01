<script setup lang="ts">
import PublicFooter from '@/components/marketing/PublicFooter.vue';
import PublicNav from '@/components/marketing/PublicNav.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';

/**
 * Public pages follow the same light and dark appearance as the app.
 * Snitch paper and ink tokens flip with the `dark` class on the document.
 */
withDefaults(
    defineProps<{
        minimal?: boolean;
    }>(),
    {
        minimal: false,
    },
);
</script>

<template>
    <div class="snitch-public-shell relative min-h-screen w-full bg-snitch-paper text-snitch-ink">
        <SeoHead />
        <div class="relative z-10 flex min-h-screen w-full flex-col">
            <PublicNav :minimal="minimal" />
            <main class="w-full flex-1">
                <slot />
            </main>
            <PublicFooter v-if="!minimal" />
        </div>
    </div>
</template>
