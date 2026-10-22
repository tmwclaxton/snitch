<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    title: string;
    payload: {
        items?: Array<{
            label: string;
            value: string;
            href?: string | null;
        }>;
    };
}>();
</script>

<template>
    <article class="self-start rounded border border-snitch-ink/10 bg-snitch-lift p-4">
        <h2 class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
            {{ title }}
        </h2>
        <ol class="mt-3 space-y-2">
            <li
                v-for="(item, index) in payload.items || []"
                :key="`${item.label}-${index}`"
                class="flex flex-wrap items-baseline justify-between gap-2 border-b border-snitch-ink/10 pb-2 text-sm last:border-b-0 last:pb-0"
            >
                <div class="flex min-w-0 flex-wrap items-baseline gap-2">
                    <span class="font-mono tabular-nums text-snitch-ink/50">{{ index + 1 }}</span>
                    <Link
                        v-if="item.href"
                        :href="item.href"
                        class="font-medium text-snitch-ink underline-offset-2 hover:underline"
                    >
                        {{ item.label }}
                    </Link>
                    <span
                        v-else
                        class="font-medium text-snitch-ink"
                    >{{ item.label }}</span>
                </div>
                <span class="font-mono tabular-nums text-snitch-ink/80">{{ item.value }}</span>
            </li>
        </ol>
    </article>
</template>
