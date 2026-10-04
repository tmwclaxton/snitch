<script setup lang="ts">
import { computed } from 'vue';
import { highlightKeyPhrase } from '@/lib/highlightPhrase';

const props = defineProps<{
    text: string;
}>();

const parts = computed(() => highlightKeyPhrase(props.text));
</script>

<template>
    <span>
        <template
            v-for="(part, index) in parts"
            :key="`${index}-${part.text}`"
        >
            <span
                v-if="part.highlight"
                class="snitch-highlight"
            >{{ part.text }}</span>
            <template v-else>{{ part.text }}</template>
        </template>
    </span>
</template>
