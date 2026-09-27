<script setup lang="ts">
defineProps<{
    reason?: string | null;
    compact?: boolean;
}>();
</script>

<template>
    <p
        class="text-slate-500"
        :class="compact ? 'text-[11px] leading-snug' : 'rounded-lg border border-dashed border-slate-200 bg-slate-50 px-3 py-2 text-xs'"
    >
        <slot>{{ reason || 'Not enough data yet.' }}</slot>
    </p>
</template>
