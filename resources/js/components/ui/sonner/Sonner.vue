<script setup lang="ts">
import { computed } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { Toaster as SonnerPrimitive } from 'vue-sonner';
import 'vue-sonner/style.css';

const { appearance } = useAppearance();

const toastTheme = computed(() => {
    if (typeof document !== 'undefined' && document.documentElement.classList.contains('dark')) {
        return 'dark';
    }

    if (appearance.value === 'dark') {
        return 'dark';
    }

    if (appearance.value === 'light') {
        return 'light';
    }

    return 'system';
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
