<script setup lang="ts">
import EmptyState from '@/components/dashboard/EmptyState.vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';

type Consistency = { filled: number; weeks: boolean[] };

type Row = {
    handle: string;
    display_name: string | null;
    avatar: string | null;
    is_own_account: boolean;
    followers: number | null;
    growth_pct: number | null;
    posts_per_week: number | null;
    consistency: Consistency;
    er: number | null;
    er_reason: string | null;
    comments_per_post: number | null;
    top_format: string | null;
    winners: number;
    engagement_share: number | null;
    posts_n: number;
    no_posts_in_period?: boolean;
    row_note?: string | null;
};

defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    rows?: Row[];
}>();

const fmt = new Intl.NumberFormat('en-GB');

function followers(value: number | null): string {
    return value == null ? '—' : fmt.format(value);
}

function pct(value: number | null): string {
    return value == null ? '—' : `${value.toFixed(1)}%`;
}

function num(value: number | null): string {
    return value == null ? '—' : value.toFixed(1);
}
</script>

<template>
    <EmptyState v-if="status !== 'ok' || !rows?.length" :reason="reason" />
    <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[880px] text-left text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <th class="py-2 pr-3 font-medium">Account</th>
                    <th class="px-2 py-2 font-medium">Followers</th>
                    <th class="px-2 py-2 font-medium">30d growth</th>
                    <th class="px-2 py-2 font-medium">Posts/wk</th>
                    <th class="px-2 py-2 font-medium">Consistency</th>
                    <th class="px-2 py-2 font-medium">ER / follower</th>
                    <th class="px-2 py-2 font-medium">Comments/post</th>
                    <th class="px-2 py-2 font-medium">Top format</th>
                    <th class="px-2 py-2 font-medium">Winners</th>
                    <th class="px-2 py-2 font-medium">Eng. share</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.handle"
                    class="border-b border-slate-100"
                    :class="row.is_own_account ? 'bg-slate-50' : ''"
                >
                    <td class="py-3 pr-3">
                        <div class="flex items-center gap-2">
                            <SnitchAvatar
                                :src="row.avatar"
                                :name="row.display_name"
                                :handle="row.handle"
                                size="sm"
                            />
                            <div>
                                <div class="font-medium text-slate-900">
                                    @{{ row.handle }}
                                    <span
                                        v-if="row.is_own_account"
                                        class="ml-1 rounded bg-slate-900 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white"
                                    >
                                        You
                                    </span>
                                </div>
                                <div v-if="row.row_note" class="text-[11px] text-slate-400">{{ row.row_note }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-2 py-3 tabular-nums text-slate-700">{{ followers(row.followers) }}</td>
                    <td class="px-2 py-3 tabular-nums text-slate-700">
                        {{ row.no_posts_in_period ? '—' : pct(row.growth_pct) }}
                    </td>
                    <td class="px-2 py-3 tabular-nums text-slate-700">
                        {{ row.no_posts_in_period ? '—' : num(row.posts_per_week) }}
                    </td>
                    <td class="px-2 py-3">
                        <div
                            v-if="!row.no_posts_in_period"
                            class="flex items-center gap-0.5"
                            :title="`${row.consistency.filled}/8 weeks`"
                        >
                            <span
                                v-for="(filled, idx) in row.consistency.weeks"
                                :key="idx"
                                class="h-2 w-2 rounded-full"
                                :class="filled ? 'bg-slate-800' : 'bg-slate-200'"
                            />
                        </div>
                        <span v-else class="text-xs text-slate-400">—</span>
                    </td>
                    <td class="px-2 py-3 tabular-nums text-slate-700">
                        <span v-if="row.no_posts_in_period" class="text-xs text-slate-400">No posts imported yet</span>
                        <span v-else-if="row.er != null">{{ row.er.toFixed(2) }}%</span>
                        <span v-else class="text-xs text-slate-400">{{ row.er_reason || '—' }}</span>
                    </td>
                    <td class="px-2 py-3 tabular-nums text-slate-700">
                        {{ row.no_posts_in_period ? '—' : num(row.comments_per_post) }}
                    </td>
                    <td class="px-2 py-3 text-slate-700">
                        {{ row.no_posts_in_period ? '—' : row.top_format || '—' }}
                    </td>
                    <td class="px-2 py-3 tabular-nums text-slate-700">
                        {{ row.no_posts_in_period ? '—' : row.winners }}
                    </td>
                    <td class="px-2 py-3">
                        <template v-if="row.no_posts_in_period">
                            <span class="text-xs text-slate-400">—</span>
                        </template>
                        <div v-else class="flex items-center gap-2">
                            <div class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-100">
                                <div
                                    class="h-full rounded-full bg-slate-700"
                                    :style="{ width: `${Math.min(100, row.engagement_share ?? 0)}%` }"
                                />
                            </div>
                            <span class="tabular-nums text-slate-700">{{ pct(row.engagement_share) }}</span>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
