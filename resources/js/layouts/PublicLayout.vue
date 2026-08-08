<script setup lang="ts">
import PublicFooter from '@/components/marketing/PublicFooter.vue';
import PublicNav from '@/components/marketing/PublicNav.vue';

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
    <div class="snitch-surface relative min-h-screen w-full overflow-x-clip">
        <div class="snitch-grain" aria-hidden="true" />
        <div class="relative z-10 flex min-h-screen w-full flex-col">
            <PublicNav :minimal="minimal" />
            <main class="w-full flex-1">
                <slot />
            </main>
            <PublicFooter v-if="!minimal" />
        </div>
    </div>
</template>
