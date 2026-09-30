<script setup lang="ts">
defineProps<{
    title: string;
    updated?: string;
}>();
</script>

<template>
    <div class="px-4 py-14 sm:px-8 sm:py-20">
        <article class="mx-auto max-w-3xl">
            <h1 class="snitch-hero-display text-4xl text-snitch-caution-fog">
                {{ title }}
            </h1>
            <p v-if="updated" class="mt-3 font-mono text-sm text-snitch-caution-fog/70">
                Last updated {{ updated }}
            </p>
            <div class="mt-8 space-y-5 text-base leading-relaxed text-snitch-caution-fog/80 [&_h2]:mt-10 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:tracking-tight [&_h2]:text-snitch-caution-fog [&_a]:font-medium [&_a]:text-snitch-caution-fog [&_a]:underline [&_a]:decoration-snitch-caution-yellow/80 [&_a]:underline-offset-2">
                <slot />
            </div>
        </article>
    </div>
</template>
