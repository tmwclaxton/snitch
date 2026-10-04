<script setup lang="ts">
import { computed } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { Toaster as SonnerPrimitive } from 'vue-sonner';
import 'vue-sonner/style.css';

const { appearance } = useAppearance();

const toastTheme = computed(() => {
    if (typeof document !== 'undefined' && document.documentElement.dataset.appNight === '1') {
        return 'dark';
    }

    return appearance.value;
});
</script>

<template>
    <SonnerPrimitive
        :theme="toastTheme"
        class="toaster group"
        position="bottom-right"
        :style="{
            '--normal-bg': 'var(--popover)',
            '--normal-text': 'var(--popover-foreground)',
            '--normal-border': 'var(--border)',
        }"
    />
</template>
