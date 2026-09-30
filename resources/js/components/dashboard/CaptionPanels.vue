<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import EmptyState from '@/components/dashboard/EmptyState.vue';

type LengthBucket = { bucket: string; n: number; pi: number | null };
type CtaRow = { type: string; share_pct: number; pi_with: number | null; pi_without: number | null; n: number };
type HashBucket = { bucket: string; n: number; pi: number | null };
type Hook = { handle: string | null; tracked_account_id?: number | null; hook: string; pattern: string; pi: number };

defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    lengthBuckets?: LengthBucket[];
    ctas?: CtaRow[];
    hashtagBuckets?: HashBucket[];
    hooks?: Hook[];
}>();

const HOOK_COLLAPSE_CHARS = 120;
const expandedHooks = ref<Record<number, boolean>>({});

function hookNeedsToggle(text: string): boolean {
    return text.trim().length > HOOK_COLLAPSE_CHARS;
}

function hookDisplay(text: string, idx: number): string {
    const value = text.trim() || 'No caption';

    if (expandedHooks.value[idx] || ! hookNeedsToggle(value)) {
        return value;
    }

    const slice = value.slice(0, HOOK_COLLAPSE_CHARS);
    const lastSpace = slice.lastIndexOf(' ');

    return lastSpace > 40 ? slice.slice(0, lastSpace) : slice;
}

function toggleHook(idx: number, event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    expandedHooks.value = {
        ...expandedHooks.value,
        [idx]: ! expandedHooks.value[idx],
    };
}

/** Bar fill relative to 1× usual, capped at 3×. */
function multiplierBarWidth(value: number | null): string {
    if (value == null || Number.isNaN(value)) {
        return '0%';
    }

    const capped = Math.min(3, Math.max(0, value));

    return `${Math.round((capped / 3) * 100)}%`;
}

function formatMultiplier(value: number | null): string | null {
    if (value == null || Number.isNaN(value)) {
        return null;
    }

    return `${value.toFixed(1)}×`;
}
</script>

<template>
    <EmptyState v-if="status !== 'ok'" :reason="reason" compact />
    <div v-else class="space-y-2">
        <p class="text-xs leading-snug text-snitch-ink/55">
            Results = a post's engagement vs that account's usual (1.0× = normal).
        </p>

        <div class="grid gap-3 sm:grid-cols-3 sm:max-w-3xl sm:gap-3">
            <div class="min-w-0 max-w-[16rem]">
                <p class="mb-1 text-xs font-medium text-snitch-ink/70">
                    Caption length vs results
                </p>
                <ul class="space-y-1">
                    <li
                        v-for="row in lengthBuckets || []"
                        :key="row.bucket"
                        class="flex items-center gap-1.5 text-sm text-snitch-ink/80"
                    >
                        <span class="w-[4.75rem] shrink-0 leading-snug">
                            {{ row.bucket }}
                            <span class="text-snitch-ink/45">from {{ row.n }} posts</span>
                        </span>
                        <div
                            class="relative h-1.5 w-14 shrink-0 overflow-hidden rounded-sm bg-snitch-fog"
                            aria-hidden="true"
                        >
                            <div
                                class="snitch-meter-fill absolute inset-y-0 left-0 rounded-sm"
                                :style="{ width: multiplierBarWidth(row.pi) }"
                            />
                            <div
                                class="absolute inset-y-0 w-px bg-snitch-ink/25"
                                style="left: 33.333%"
                            />
                        </div>
                        <span
                            v-if="formatMultiplier(row.pi)"
                            class="shrink-0 tabular-nums font-medium text-snitch-ink"
                        >
                            {{ formatMultiplier(row.pi) }}
                        </span>
                        <span
                            v-else
                            class="shrink-0 text-xs text-snitch-ink/45"
                        >
                            too few posts
                        </span>
                    </li>
                </ul>
            </div>

            <div class="min-w-0 max-w-[16rem]">
                <p class="mb-1 text-xs font-medium text-snitch-ink/70">
                    Call to action
                </p>
                <EmptyState v-if="!(ctas || []).length" reason="Not enough posts in this bucket" compact />
                <ul v-else class="space-y-1">
                    <li
                        v-for="row in ctas"
                        :key="row.type"
                        class="flex items-center gap-1.5 text-sm text-snitch-ink/80"
                    >
                        <span class="w-[5.5rem] shrink-0 break-words leading-snug">
                            {{ row.type }}
                            <span class="text-snitch-ink/45">{{ row.share_pct }}%</span>
                        </span>
                        <div
                            class="relative h-1.5 w-14 shrink-0 overflow-hidden rounded-sm bg-snitch-fog"
                            aria-hidden="true"
                        >
                            <div
                                class="snitch-meter-fill absolute inset-y-0 left-0 rounded-sm"
                                :style="{ width: multiplierBarWidth(row.pi_with) }"
                            />
                            <div
                                class="absolute inset-y-0 w-px bg-snitch-ink/25"
                                style="left: 33.333%"
                            />
                        </div>
                        <span
                            v-if="formatMultiplier(row.pi_with)"
                            class="shrink-0 tabular-nums font-medium text-snitch-ink"
                        >
                            {{ formatMultiplier(row.pi_with) }}
                        </span>
                        <span
                            v-else
                            class="shrink-0 text-xs text-snitch-ink/45"
                        >
                            too few posts
                        </span>
                    </li>
                </ul>
            </div>

            <div class="min-w-0 max-w-[16rem]">
                <p class="mb-1 text-xs font-medium text-snitch-ink/70">
                    Hashtag count
                </p>
                <ul class="space-y-1">
                    <li
                        v-for="row in hashtagBuckets || []"
                        :key="row.bucket"
                        class="flex items-center gap-1.5 text-sm text-snitch-ink/80"
                    >
                        <span class="w-[4.75rem] shrink-0 leading-snug">
                            {{ row.bucket }} tags
                            <span class="text-snitch-ink/45">from {{ row.n }} posts</span>
                        </span>
                        <div
                            class="relative h-1.5 w-14 shrink-0 overflow-hidden rounded-sm bg-snitch-fog"
                            aria-hidden="true"
                        >
                            <div
                                class="snitch-meter-fill absolute inset-y-0 left-0 rounded-sm"
                                :style="{ width: multiplierBarWidth(row.pi) }"
                            />
                            <div
                                class="absolute inset-y-0 w-px bg-snitch-ink/25"
                                style="left: 33.333%"
                            />
                        </div>
                        <span
                            v-if="formatMultiplier(row.pi)"
                            class="shrink-0 tabular-nums font-medium text-snitch-ink"
                        >
                            {{ formatMultiplier(row.pi) }}
                        </span>
                        <span
                            v-else
                            class="shrink-0 text-xs text-snitch-ink/45"
                        >
                            too few posts
                        </span>
                    </li>
                </ul>
            </div>
        </div>

        <div>
            <p class="mb-1 text-xs font-medium text-snitch-ink/70">Winning hooks</p>
            <EmptyState v-if="!(hooks || []).length" reason="No winner hooks yet" compact />
            <ul
                v-else
                class="grid grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-1.5"
            >
                <li
                    v-for="(hook, idx) in hooks"
                    :key="idx"
                    class="min-w-0 rounded border border-snitch-ink/10 bg-snitch-fog/60 px-1.5 py-1 text-sm leading-snug text-snitch-ink/80"
                >
                    <div class="flex flex-wrap items-baseline gap-x-1 gap-y-0.5">
                        <span class="rounded bg-snitch-lift px-1 text-xs uppercase text-snitch-ink/55">{{ hook.pattern }}</span>
                        <span class="font-medium tabular-nums text-snitch-ink">{{ hook.pi.toFixed(1) }}× usual</span>
                        <Link
                            v-if="hook.tracked_account_id"
                            :href="competitorShow.url(hook.tracked_account_id)"
                            class="break-all text-snitch-ink/55 hover:underline"
                        >
                            @{{ hook.handle }}
                        </Link>
                        <span v-else class="break-all text-snitch-ink/55">@{{ hook.handle }}</span>
                    </div>
                    <p class="mt-0.5 break-words text-snitch-ink/70">{{ hookDisplay(hook.hook || '', idx) }}</p>
                    <button
                        v-if="hookNeedsToggle(hook.hook || '')"
                        type="button"
                        class="mt-0.5 text-xs font-medium text-snitch-ink/55 underline-offset-2 hover:text-snitch-ink hover:underline"
                        @click="toggleHook(idx, $event)"
                    >
                        {{ expandedHooks[idx] ? 'less' : 'more' }}
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
