<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;
</script>

<template>
    <div class="snitch-seg" role="group" aria-label="Appearance">
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            class="snitch-seg-item"
            :class="appearance === value ? 'snitch-seg-item-active' : ''"
            @click="updateAppearance(value)"
        >
            <component :is="Icon" class="h-3.5 w-3.5" />
            {{ label }}
        </button>
    </div>
</template>
