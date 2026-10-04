<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div class="flex aspect-square size-9 items-center justify-center">
        <AppLogoIcon class="h-9 w-9" />
    </div>
    <div class="ml-1.5 grid min-w-0 flex-1 text-left text-sm">
        <span class="snitch-display relative truncate text-lg leading-tight font-semibold tracking-tight text-snitch-ink">
            <span
                class="pointer-events-none absolute inset-0 translate-x-[1px] translate-y-[1px] text-snitch-spot opacity-55 mix-blend-multiply select-none dark:mix-blend-plus-lighter dark:opacity-70"
                aria-hidden="true"
            >Snitch</span>
            <span class="relative">Snitch</span>
        </span>
    </div>
</template>
