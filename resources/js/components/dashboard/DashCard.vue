<script setup lang="ts">
import { Info } from '@lucide/vue';
import { ref } from 'vue';

defineProps<{
    title: string;
    why?: string | null;
    formula?: string | null;
    anchor?: string | null;
}>();

const open = ref(false);
</script>

<template>
    <section
        :id="anchor || undefined"
        class="scroll-mt-24 rounded-xl border border-slate-200 bg-white p-5"
    >
        <div class="mb-1 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-slate-900">{{ title }}</h2>
                <p v-if="why" class="mt-0.5 text-sm text-slate-500">{{ why }}</p>
            </div>
            <button
                v-if="formula"
                type="button"
                class="relative shrink-0 rounded-md p-1 text-slate-400 hover:bg-slate-50 hover:text-slate-700"
                :aria-label="'Formula for ' + title"
                @click="open = !open"
                @blur="open = false"
            >
                <Info class="h-4 w-4" />
                <div
                    v-if="open"
                    class="absolute right-0 z-20 mt-2 w-64 rounded-lg border border-slate-200 bg-white p-3 text-left text-xs leading-relaxed text-slate-600 shadow-sm"
                >
                    {{ formula }}
                </div>
            </button>
        </div>
        <div class="mt-4">
            <slot />
        </div>
    </section>
</template>
