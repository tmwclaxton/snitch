<script setup lang="ts">
defineProps<{
    title: string;
    updated?: string;
}>();
</script>

<template>
    <div class="px-5 py-12 sm:px-8 sm:py-16">
        <article class="snitch-doc relative mx-auto max-w-3xl px-6 py-10 sm:px-10 sm:py-12">
            <h1 class="snitch-display text-4xl text-snitch-ink">
                {{ title }}
            </h1>
            <p v-if="updated" class="mt-2 text-sm text-snitch-ink/50">
                Last updated {{ updated }}
            </p>
            <div
                class="prose-snitch relative z-10 mt-8 space-y-5 text-base leading-relaxed text-snitch-ink/85"
            >
                <slot />
            </div>
        </article>
    </div>
</template>
