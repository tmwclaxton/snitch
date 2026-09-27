<script setup lang="ts">
import EmptyState from '@/components/dashboard/EmptyState.vue';
import { useAccountColours } from '@/composables/useAccountColours';

type Insight = {
    category: string;
    text: string;
    score: number;
    n: number;
    links_to: string;
};

defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    items?: Insight[];
}>();

const { colourFor } = useAccountColours();

function handleFromText(text: string): string | null {
    const match = text.match(/@([a-zA-Z0-9._]+)/);

    return match?.[1] ?? null;
}

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function htmlText(text: string): string {
    const escaped = escapeHtml(text);

    return escaped.replace(/\*\*(.+?)\*\*/g, '<strong class="font-semibold text-slate-900">$1</strong>');
}

function scrollTo(id: string): void {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<template>
    <EmptyState v-if="status !== 'ok' || !items?.length" :reason="reason" />
    <ul v-else class="space-y-3">
        <li
            v-for="item in items"
            :key="item.category + item.text"
            class="flex gap-3 rounded-lg border border-slate-100 bg-slate-50/60 px-3 py-3"
        >
            <span
                class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full"
                :style="{ backgroundColor: colourFor(handleFromText(item.text)) }"
            />
            <div class="min-w-0 flex-1">
                <p class="text-sm leading-relaxed text-slate-700" v-html="htmlText(item.text)" />
                <button
                    type="button"
                    class="mt-1 text-xs font-medium text-slate-500 underline-offset-2 hover:text-slate-800 hover:underline"
                    @click="scrollTo(item.links_to)"
                >
                    see why →
                </button>
            </div>
        </li>
    </ul>
</template>
