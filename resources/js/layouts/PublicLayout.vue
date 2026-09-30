<script setup lang="ts">
import PublicFooter from '@/components/marketing/PublicFooter.vue';
import PublicNav from '@/components/marketing/PublicNav.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';

/**
 * Public pages are pinned to the Caution Tape night palette, like the landing
 * page: the shell's `dark` class scopes the night snitch tokens and `dark:`
 * variants regardless of the visitor's OS / appearance setting.
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
    <div class="snitch-public-shell dark relative min-h-screen w-full bg-snitch-caution-ink text-snitch-caution-fog">
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
