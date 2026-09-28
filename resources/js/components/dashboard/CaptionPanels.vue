<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
</script>

<template>
    <EmptyState v-if="status !== 'ok'" :reason="reason" compact />
    <div v-else class="space-y-1.5">
        <div class="grid gap-2 sm:grid-cols-3">
            <div>
                <p class="mb-1 text-[10px] font-medium uppercase tracking-wide text-slate-500">Length vs PI</p>
                <ul class="space-y-1">
                    <li
                        v-for="row in lengthBuckets || []"
                        :key="row.bucket"
                        class="flex items-center justify-between gap-2 text-[11px] text-slate-700"
                    >
                        <span>{{ row.bucket }} <span class="text-slate-400">n={{ row.n }}</span></span>
                        <span class="tabular-nums font-medium">{{ row.pi != null ? `${row.pi.toFixed(1)}x` : '-' }}</span>
                    </li>
                </ul>
            </div>
            <div>
                <p class="mb-1 text-[10px] font-medium uppercase tracking-wide text-slate-500">CTAs</p>
                <EmptyState v-if="!(ctas || []).length" reason="Not enough posts in this bucket" compact />
                <ul v-else class="space-y-1">
                    <li
                        v-for="row in ctas"
                        :key="row.type"
                        class="flex items-center justify-between gap-2 text-[11px] text-slate-700"
                    >
                        <span class="min-w-0 break-words leading-snug">{{ row.type }} <span class="text-slate-400">{{ row.share_pct }}%</span></span>
                        <span class="shrink-0 tabular-nums font-medium">{{ row.pi_with != null ? `${row.pi_with.toFixed(1)}x` : '-' }}</span>
                    </li>
                </ul>
            </div>
            <div>
                <p class="mb-1 text-[10px] font-medium uppercase tracking-wide text-slate-500">Hashtags</p>
                <ul class="space-y-1">
                    <li
                        v-for="row in hashtagBuckets || []"
                        :key="row.bucket"
                        class="flex items-center justify-between gap-2 text-[11px] text-slate-700"
                    >
                        <span>{{ row.bucket }} tags <span class="text-slate-400">n={{ row.n }}</span></span>
                        <span class="tabular-nums font-medium">{{ row.pi != null ? `${row.pi.toFixed(1)}x` : '-' }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <div>
            <p class="mb-1 text-[10px] font-medium uppercase tracking-wide text-slate-500">Winning hooks</p>
            <EmptyState v-if="!(hooks || []).length" reason="No winner hooks yet" compact />
            <ul
                v-else
                class="grid grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-1.5"
            >
                <li
                    v-for="(hook, idx) in hooks"
                    :key="idx"
                    class="min-w-0 rounded border border-slate-100 bg-slate-50/80 px-1.5 py-1 text-[11px] leading-snug text-slate-700"
                >
                    <div class="flex flex-wrap items-baseline gap-x-1 gap-y-0.5">
                        <span class="rounded bg-white px-1 text-[9px] uppercase text-slate-500">{{ hook.pattern }}</span>
                        <span class="font-medium tabular-nums text-slate-900">{{ hook.pi.toFixed(1) }}x</span>
                        <Link
                            v-if="hook.tracked_account_id"
                            :href="competitorShow.url(hook.tracked_account_id)"
                            class="break-all text-slate-500 hover:underline"
                        >
                            @{{ hook.handle }}
                        </Link>
                        <span v-else class="break-all text-slate-500">@{{ hook.handle }}</span>
                    </div>
                    <p class="mt-0.5 line-clamp-3 text-slate-600">{{ hook.hook }}</p>
                </li>
            </ul>
        </div>
    </div>
</template>
