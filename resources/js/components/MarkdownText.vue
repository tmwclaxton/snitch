<script setup lang="ts">
import { computed } from 'vue';
import { renderMarkdown } from '@/lib/markdown';

const props = withDefaults(
    defineProps<{
        source?: string | null;
        html?: string | null;
    }>(),
    {
        source: null,
        html: null,
    },
);

const rendered = computed(() => {
    if (props.html) {
        return props.html;
    }

    return renderMarkdown(props.source);
});
</script>

<template>
    <div
        v-if="rendered"
        class="snitch-md"
        v-html="rendered"
    />
</template>
