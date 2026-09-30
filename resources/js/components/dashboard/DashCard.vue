<script setup lang="ts">
import { Info } from '@lucide/vue';
import { ref } from 'vue';

const props = defineProps<{
    title: string;
    why?: string | null;
    formula?: string | null;
    anchor?: string | null;
    class?: string | null;
}>();

const open = ref(false);
</script>

<template>
    <section
        :id="anchor || undefined"
        class="scroll-mt-14 rounded border border-snitch-ink/10 bg-snitch-lift px-1.5 py-0.5 transition-shadow duration-500"
        :class="props.class"
    >
        <div class="mb-0.5 flex h-6 shrink-0 items-center justify-between gap-2">
            <h2 class="min-w-0 font-display text-sm font-semibold leading-snug tracking-tight text-snitch-ink">{{ title }}</h2>
            <button
                v-if="formula || why"
                type="button"
                class="relative shrink-0 rounded p-0.5 text-snitch-ink/45 hover:bg-snitch-fog hover:text-snitch-ink"
                :aria-label="'About ' + title"
                @click="open = !open"
                @blur="open = false"
            >
                <Info class="h-3.5 w-3.5" />
                <div
                    v-if="open"
                    class="absolute right-0 z-20 mt-2 w-64 rounded-lg border border-snitch-ink/10 bg-snitch-lift p-2 text-left text-xs leading-relaxed text-snitch-ink/70 shadow-sm"
                >
                    <p v-if="why" class="mb-1.5 text-snitch-ink/80">{{ why }}</p>
                    <p v-if="formula" class="text-snitch-ink/55">{{ formula }}</p>
                </div>
            </button>
        </div>
        <div class="flex min-h-0 min-w-0 flex-1 flex-col">
            <slot />
        </div>
    </section>
</template>
