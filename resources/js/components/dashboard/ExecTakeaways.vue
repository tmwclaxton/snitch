<script setup lang="ts">
defineProps<{
    items: Array<{ metric: string; text: string }>;
}>();
</script>

<template>
    <ul
        v-if="items.length"
        class="flex flex-wrap gap-2"
    >
        <li
            v-for="(item, index) in items"
            :key="`${item.metric}-${index}`"
            class="min-w-[14rem] flex-1 rounded border border-snitch-ink/10 bg-snitch-lift p-3"
        >
            <p class="font-display text-2xl font-semibold tabular-nums text-snitch-ink leading-none">
                {{ item.metric }}
            </p>
            <p class="mt-2 text-sm leading-snug text-snitch-ink/80">
                {{ item.text }}
            </p>
        </li>
    </ul>
</template>
