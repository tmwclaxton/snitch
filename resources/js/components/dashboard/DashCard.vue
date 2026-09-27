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
        class="scroll-mt-16 rounded-lg border border-slate-200 bg-white p-3 transition-shadow duration-500"
        :class="props.class"
    >
        <div class="mb-2 flex h-7 items-center justify-between gap-2">
            <h2 class="truncate text-[11px] font-semibold uppercase tracking-wide text-slate-900">{{ title }}</h2>
            <button
                v-if="formula || why"
                type="button"
                class="relative shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-50 hover:text-slate-700"
                :aria-label="'About ' + title"
                @click="open = !open"
                @blur="open = false"
            >
                <Info class="h-3.5 w-3.5" />
                <div
                    v-if="open"
                    class="absolute right-0 z-20 mt-2 w-64 rounded-lg border border-slate-200 bg-white p-2.5 text-left text-[11px] leading-relaxed text-slate-600 shadow-sm"
                >
                    <p v-if="why" class="mb-1.5 text-slate-700">{{ why }}</p>
                    <p v-if="formula" class="text-slate-500">{{ formula }}</p>
                </div>
            </button>
        </div>
        <slot />
    </section>
</template>
