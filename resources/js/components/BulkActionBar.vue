<script setup lang="ts">
withDefaults(
    defineProps<{
        count: number;
        label: string;
        ariaLabel?: string;
    }>(),
    {
        ariaLabel: undefined,
    },
);
</script>

<template>
    <div
        class="snitch-scrap pointer-events-auto relative w-full max-w-3xl px-3 py-3 pt-4 sm:px-4"
        role="toolbar"
        :aria-label="ariaLabel ?? `${count} ${label}`"
    >
        <span class="snitch-tape left-4 -top-2 scale-90" aria-hidden="true" />
        <div
            class="relative z-10 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3"
        >
            <p class="text-sm text-snitch-ink/70">
                <span class="font-medium text-snitch-ink">{{ count }}</span>
                {{ label }}
            </p>
            <div class="flex flex-wrap items-center gap-1.5 sm:justify-end">
                <slot />
            </div>
        </div>
    </div>
</template>
