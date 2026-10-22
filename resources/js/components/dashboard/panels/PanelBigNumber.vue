<script setup lang="ts">
defineProps<{
    title: string;
    payload: {
        label?: string;
        value?: string;
        note?: string | null;
        accent?: boolean;
    };
}>();
</script>

<template>
    <article class="flex min-h-[7rem] flex-col justify-between rounded border border-snitch-ink/10 bg-snitch-lift p-4">
        <p class="text-sm font-medium text-snitch-ink/70">
            {{ payload.label || title }}
        </p>
        <p
            class="mt-3 font-mono text-3xl font-semibold tracking-tight tabular-nums leading-none"
            :class="payload.accent ? 'snitch-stat-accent' : 'text-snitch-ink'"
        >
            {{ payload.value }}
        </p>
        <p
            v-if="payload.note"
            class="mt-2 text-sm leading-snug text-snitch-ink/70"
        >
            {{ payload.note }}
        </p>
    </article>
</template>
