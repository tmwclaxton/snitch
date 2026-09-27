<script setup lang="ts">
defineProps<{
    reason?: string | null;
    compact?: boolean;
}>();
</script>

<template>
    <div
        class="rounded-lg border border-dashed border-slate-200 bg-slate-50 text-slate-500"
        :class="compact ? 'px-3 py-2 text-xs' : 'px-4 py-6 text-sm'"
    >
        <slot>{{ reason || 'Not enough data yet.' }}</slot>
    </div>
</template>
