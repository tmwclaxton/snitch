<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import { postTypeLabel } from '@/lib/posts';

type Consistency = { filled: number; weeks: boolean[] };

type Row = {
    tracked_account_id?: number | null;
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

const props = defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    rows?: Row[];
}>();

const fmt = new Intl.NumberFormat('en-GB');

/** Hide Growth when every row is still waiting on a second snapshot. */
const showGrowth = computed(() =>
    (props.rows ?? []).some((row) => ! row.no_posts_in_period && row.growth_pct != null),
);

function followers(value: number | null): string {
    return value == null ? '-' : fmt.format(value);
}

function pct(value: number | null): string {
    return value == null ? '-' : `${value.toFixed(1)}%`;
}

function num(value: number | null): string {
    return value == null ? '-' : value.toFixed(1);
}

function accountLabel(row: Row): string {
    return row.is_own_account ? 'You' : `@${row.handle}`;
}

function formatUnderHandle(row: Row): string | null {
    if (row.no_posts_in_period) {
        return 'no posts yet';
    }

    if (! row.top_format || row.er == null) {
        return null;
    }

    return postTypeLabel(row.top_format);
}
</script>

<template>
    <div class="flex h-full min-h-0 min-w-0 flex-1 flex-col">
        <EmptyState v-if="status !== 'ok' || !rows?.length" :reason="reason" compact />
        <table
            v-else
            class="h-full min-h-0 w-full flex-1 table-fixed text-left text-sm"
            :style="{ '--lb-rows': String(rows?.length || 1) }"
        >
            <colgroup>
                <col :style="{ width: showGrowth ? '40%' : '44%' }">
                <col style="width: 14%">
                <col v-if="showGrowth" style="width: 12%">
                <col style="width: 14%">
                <col style="width: 14%">
                <col style="width: 10%">
            </colgroup>
            <thead>
                <tr class="border-b border-slate-200 text-xs text-slate-500">
                    <th class="py-1 pr-3 text-left font-medium">Account</th>
                    <th class="py-1 pl-2 pr-1 text-right font-medium leading-tight">Followers</th>
                    <th
                        v-if="showGrowth"
                        class="py-1 pl-2 pr-1 text-right font-medium leading-tight"
                    >
                        Growth
                    </th>
                    <th class="py-1 pl-2 pr-1 text-right font-medium leading-tight">
                        Posts
                        <br>
                        / wk
                    </th>
                    <th class="py-1 pl-2 pr-1 text-right font-medium leading-tight">
                        Eng.
                        <br>
                        rate
                    </th>
                    <th class="py-1 pl-2 pr-1 text-right font-medium leading-tight">Win</th>
                </tr>
            </thead>
            <tbody class="h-full">
                <tr
                    v-for="row in rows"
                    :key="row.handle"
                    class="border-b border-slate-100 last:border-b-0"
                    :class="[
                        row.is_own_account ? 'bg-slate-50' : '',
                        row.no_posts_in_period ? 'text-slate-400' : '',
                    ]"
                    :style="{ height: `calc(100% / var(--lb-rows))` }"
                >
                <td class="py-1.5 pr-3 align-middle">
                    <div class="flex min-w-0 items-start gap-1.5" :title="row.row_note || undefined">
                        <SnitchAvatar
                            :src="row.avatar"
                            :name="row.display_name"
                            :handle="row.handle"
                            size="sm"
                            class="!size-4 shrink-0"
                            :class="row.no_posts_in_period ? 'opacity-50' : ''"
                        />
                        <div class="min-w-0">
                            <Link
                                v-if="row.tracked_account_id"
                                :href="competitorShow.url(row.tracked_account_id)"
                                class="break-words font-medium leading-snug hover:underline"
                                :class="row.no_posts_in_period ? 'text-slate-400' : 'text-slate-900'"
                            >
                                {{ accountLabel(row) }}
                            </Link>
                            <span
                                v-else
                                class="break-words font-medium leading-snug"
                                :class="row.no_posts_in_period ? 'text-slate-400' : 'text-slate-900'"
                            >
                                {{ accountLabel(row) }}
                            </span>
                            <p
                                v-if="formatUnderHandle(row)"
                                class="mt-0.5 text-xs leading-snug"
                                :class="row.no_posts_in_period ? 'text-slate-400' : 'text-slate-500'"
                            >
                                {{ formatUnderHandle(row) }}
                            </p>
                        </div>
                    </div>
                </td>
                <td
                    class="py-1.5 pl-2 pr-1 text-right align-middle tabular-nums"
                    :class="row.no_posts_in_period ? 'text-slate-400' : 'text-slate-700'"
                >
                    {{ followers(row.followers) }}
                </td>
                <td
                    v-if="showGrowth"
                    class="py-1.5 pl-2 pr-1 text-right align-middle tabular-nums"
                    :class="row.no_posts_in_period ? 'text-slate-400' : 'text-slate-700'"
                >
                    {{ row.no_posts_in_period ? '-' : pct(row.growth_pct) }}
                </td>
                <td
                    class="py-1.5 pl-2 pr-1 text-right align-middle tabular-nums"
                    :class="row.no_posts_in_period ? 'text-slate-400' : 'text-slate-700'"
                >
                    {{ row.no_posts_in_period ? '-' : num(row.posts_per_week) }}
                </td>
                <td
                    class="py-1.5 pl-2 pr-1 text-right align-middle tabular-nums"
                    :class="row.no_posts_in_period ? 'text-slate-400' : 'text-slate-700'"
                >
                    <span
                        v-if="row.no_posts_in_period || row.er == null"
                        class="text-slate-400"
                        :title="row.er_reason || undefined"
                    >-</span>
                    <span v-else>{{ row.er.toFixed(1) }}%</span>
                </td>
                <td
                    class="py-1.5 pl-2 pr-1 text-right align-middle tabular-nums"
                    :class="row.no_posts_in_period ? 'text-slate-400' : 'text-slate-700'"
                >
                    {{ row.no_posts_in_period ? '-' : row.winners }}
                </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
