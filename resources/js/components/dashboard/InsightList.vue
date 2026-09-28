<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
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

const props = withDefaults(
    defineProps<{
        status: 'ok' | 'insufficient' | 'empty';
        reason?: string | null;
        items?: Insight[];
        /** Lowercased handle → tracked account id for @mentions in insight copy. */
        trackerIds?: Record<string, number>;
        /** Collapsed list length before "Show all". */
        collapsedCount?: number;
    }>(),
    { collapsedCount: 3 },
);

const { colourFor } = useAccountColours();
const openKey = ref<string | null>(null);
const expanded = ref(false);

const allItems = computed(() => props.items ?? []);
const hasMore = computed(() => allItems.value.length > props.collapsedCount);
const visibleItems = computed(() =>
    expanded.value ? allItems.value : allItems.value.slice(0, props.collapsedCount),
);

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
    const withBold = escaped.replace(/\*\*(.+?)\*\*/g, '<strong class="font-semibold text-slate-900">$1</strong>');
    const ids = props.trackerIds ?? {};

    return withBold.replace(/@([a-zA-Z0-9._]+)/g, (match, handle: string) => {
        const id = ids[handle.toLowerCase()];

        if (! id) {
            return match;
        }

        return `<a href="${competitorShow.url(id)}" data-tracker-id="${id}" class="font-medium text-slate-800 underline-offset-2 hover:underline">${match}</a>`;
    });
}

function onInsightClick(event: MouseEvent): void {
    const target = event.target;

    if (! (target instanceof Element)) {
        return;
    }

    const link = target.closest('a[data-tracker-id]');

    if (! (link instanceof HTMLAnchorElement)) {
        return;
    }

    const id = Number(link.dataset.trackerId);

    if (! Number.isFinite(id) || id <= 0) {
        return;
    }

    event.preventDefault();
    router.visit(competitorShow.url(id));
}
</script>

<template>
    <EmptyState v-if="status !== 'ok' || !items?.length" :reason="reason" compact />
    <div v-else class="min-h-0">
        <ul class="space-y-0.5">
            <li
                v-for="item in visibleItems"
                :key="itemKey(item)"
                class="min-w-0 border-b border-slate-100 py-1 last:border-b-0"
            >
                <div class="flex items-start gap-1.5">
                    <span
                        class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full"
                        :style="{ backgroundColor: colourFor(handleFromText(item.text)) }"
                    />
                    <p
                        class="min-w-0 flex-1 text-sm leading-snug text-slate-700"
                        @click="onInsightClick"
                    >
                        <span v-html="htmlText(item.text)" />
                        <button
                            type="button"
                            class="ml-1 whitespace-nowrap text-xs font-medium text-slate-500 underline-offset-2 hover:text-slate-800 hover:underline"
                            @click="toggle(item)"
                        >
                            see why →
                        </button>
                    </p>
                </div>
                <div
                    v-if="openKey === itemKey(item)"
                    class="mt-1 ml-3 rounded border border-slate-200 bg-slate-50 px-2 py-1.5 text-xs leading-snug text-slate-600"
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
        <button
            v-if="hasMore"
            type="button"
            class="mt-1 text-xs font-medium text-slate-600 underline-offset-2 hover:text-slate-900 hover:underline"
            @click="expanded = !expanded"
        >
            {{ expanded ? 'Show less' : `Show all (${allItems.length})` }}
        </button>
    </div>
</template>
