<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import { useAccountColours } from '@/composables/useAccountColours';
import { followDashboardLink } from '@/lib/dashboardAnchors';

type Insight = {
    category: string;
    text: string;
    score: number;
    n: number;
    links_to: string;
    detail?: string | null;
    post_id?: number | null;
};

defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    items?: Insight[];
}>();

const { colourFor } = useAccountColours();
const openKey = ref<string | null>(null);

function itemKey(item: Insight): string {
    return item.category + '::' + item.text;
}

function toggle(item: Insight): void {
    const key = itemKey(item);
    openKey.value = openKey.value === key ? null : key;
}

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
</script>

<template>
    <EmptyState v-if="status !== 'ok' || !items?.length" :reason="reason" compact />
    <ul v-else class="grid gap-x-3 gap-y-0.5 sm:grid-cols-2">
        <li
            v-for="item in items"
            :key="itemKey(item)"
            class="min-w-0 border-b border-slate-100 py-1 last:border-b-0 sm:odd:pr-1"
        >
            <div class="flex items-start gap-1.5">
                <span
                    class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full"
                    :style="{ backgroundColor: colourFor(handleFromText(item.text)) }"
                />
                <p class="min-w-0 flex-1 text-[11px] leading-snug text-slate-700">
                    <span v-html="htmlText(item.text)" />
                    <button
                        type="button"
                        class="ml-1 whitespace-nowrap text-[10px] font-medium text-slate-500 underline-offset-2 hover:text-slate-800 hover:underline"
                        @click="toggle(item)"
                    >
                        see why →
                    </button>
                </p>
            </div>
            <div
                v-if="openKey === itemKey(item)"
                class="mt-1 ml-3 rounded border border-slate-200 bg-slate-50 px-2 py-1.5 text-[10px] leading-snug text-slate-600"
            >
                <p>{{ item.detail || `n=${item.n}` }}</p>
                <div class="mt-1 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="font-medium text-slate-700 underline-offset-2 hover:underline"
                        @click="followDashboardLink(item.links_to)"
                    >
                        Jump to evidence →
                    </button>
                    <Link
                        v-if="item.post_id"
                        :href="feedShow.url(item.post_id)"
                        class="font-medium text-slate-700 underline-offset-2 hover:underline"
                    >
                        Open post →
                    </Link>
                </div>
            </div>
        </li>
    </ul>
</template>
