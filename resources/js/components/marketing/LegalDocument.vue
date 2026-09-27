<script setup lang="ts">
defineProps<{
    title: string;
    updated?: string;
}>();
</script>

<template>
    <div class="px-4 py-14 sm:px-8 sm:py-20">
        <article class="mx-auto max-w-3xl">
            <h1 class="text-4xl font-semibold tracking-tight text-neutral-950">
                {{ title }}
            </h1>
            <p v-if="updated" class="mt-2 text-sm text-neutral-500">
                Last updated {{ updated }}
            </p>
            <div class="mt-8 space-y-5 text-base leading-relaxed text-neutral-700 [&_h2]:mt-10 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:tracking-tight [&_h2]:text-neutral-950 [&_a]:font-medium [&_a]:text-neutral-950 [&_a]:underline [&_a]:decoration-[#F0C400]/70 [&_a]:underline-offset-2">
                <slot />
            </div>
        </article>
    </div>
</template>
