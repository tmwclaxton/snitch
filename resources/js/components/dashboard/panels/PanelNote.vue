<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    title: string;
    payload: {
        body?: string;
        action_label?: string | null;
        action_href?: string | null;
    };
}>();
</script>

<template>
    <article class="flex h-full flex-col rounded border border-snitch-ink/10 bg-snitch-lift p-4">
        <h2 class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
            {{ title }}
        </h2>
        <p class="mt-3 text-base leading-relaxed text-snitch-ink">
            {{ payload.body }}
        </p>
        <Link
            v-if="payload.action_href && payload.action_label"
            :href="payload.action_href"
            class="snitch-btn snitch-btn-spot mt-4 inline-flex w-fit px-3 py-1.5 text-sm font-medium"
        >
            {{ payload.action_label }}
        </Link>
    </article>
</template>
