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
    const el = document.getElementById(id);

    if (!el) {
        return;
    }

    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    el.classList.add('ring-2', 'ring-slate-900', 'ring-offset-2');
    window.setTimeout(() => {
        el.classList.remove('ring-2', 'ring-slate-900', 'ring-offset-2');
    }, 1600);
}
</script>

<template>
    <EmptyState v-if="status !== 'ok' || !items?.length" :reason="reason" compact />
    <ul v-else class="grid gap-1 sm:grid-cols-2">
        <li
            v-for="item in items"
            :key="item.category + item.text"
            class="flex min-h-9 items-start gap-1.5 rounded border border-slate-100 bg-slate-50/50 px-2 py-1"
        >
            <span
                class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full"
                :style="{ backgroundColor: colourFor(handleFromText(item.text)) }"
            />
            <p class="min-w-0 flex-1 text-[11px] leading-snug text-slate-700">
                <span v-html="htmlText(item.text)" />
                <span class="ml-1 whitespace-nowrap text-[10px] text-slate-400">n={{ item.n }}</span>
                <button
                    type="button"
                    class="ml-1 whitespace-nowrap text-[10px] font-medium text-slate-500 underline-offset-2 hover:text-slate-800 hover:underline"
                    @click="scrollTo(item.links_to)"
                >
                    see why →
                </button>
            </p>
        </li>
    </ul>
</template>
