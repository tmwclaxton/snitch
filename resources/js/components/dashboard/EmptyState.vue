<script setup lang="ts">
defineProps<{
    reason?: string | null;
    compact?: boolean;
}>();
</script>

<template>
    <p
        class="text-snitch-ink/55"
        :class="compact ? 'text-xs leading-snug' : 'rounded-lg border border-dashed border-snitch-ink/10 bg-snitch-fog px-3 py-2 text-sm'"
    >
        <slot>{{ reason || 'Not enough data yet.' }}</slot>
    </p>
</template>
