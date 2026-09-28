<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import { postTypeLabel, postTypeShortLabel } from '@/lib/posts';

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

defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    rows?: Row[];
}>();

const fmt = new Intl.NumberFormat('en-GB');

function followers(value: number | null): string {
    return value == null ? '-' : fmt.format(value);
}

function pct(value: number | null): string {
    return value == null ? '-' : `${value.toFixed(1)}%`;
}

function num(value: number | null): string {
    return value == null ? '-' : value.toFixed(1);
}

function formatCell(value: string | null): string {
    if (!value) {
        return '-';
    }

    return postTypeShortLabel(value);
}
</script>

<template>
    <EmptyState v-if="status !== 'ok' || !rows?.length" :reason="reason" compact />
    <table v-else class="w-full table-fixed text-left text-[11px]">
        <colgroup>
            <col class="w-[32%]">
            <col class="w-[13%]">
            <col class="w-[12%]">
            <col class="w-[12%]">
            <col class="w-[11%]">
            <col class="w-[10%]">
            <col class="w-[10%]">
        </colgroup>
        <thead>
            <tr class="border-b border-slate-200 text-[9px] uppercase tracking-wide text-slate-500">
                <th class="h-7 pr-1 font-medium">Account</th>
                <th class="h-7 px-1 font-medium">Followers</th>
                <th class="h-7 px-1 font-medium">Growth</th>
                <th class="h-7 px-1 font-medium">Posts/wk</th>
                <th class="h-7 px-1 font-medium">ER</th>
                <th class="h-7 px-1 font-medium">Format</th>
                <th class="h-7 px-1 font-medium">Win</th>
            </tr>
        </thead>
        <tbody>
            <tr
                v-for="row in rows"
                :key="row.handle"
                class="border-b border-slate-100"
                :class="row.is_own_account ? 'bg-slate-50' : ''"
            >
                <td class="py-1 pr-1 align-top">
                    <div class="flex min-w-0 items-start gap-1" :title="row.row_note || undefined">
                        <SnitchAvatar
                            :src="row.avatar"
                            :name="row.display_name"
                            :handle="row.handle"
                            size="sm"
                            class="!size-4 shrink-0"
                        />
                        <Link
                            v-if="row.tracked_account_id"
                            :href="competitorShow.url(row.tracked_account_id)"
                            class="break-words font-medium leading-snug text-slate-900 hover:underline"
                        >
                            <template v-if="row.is_own_account">You</template>
                            <template v-else>@{{ row.handle }}</template>
                        </Link>
                        <span v-else class="break-words font-medium leading-snug text-slate-900">
                            <template v-if="row.is_own_account">You</template>
                            <template v-else>@{{ row.handle }}</template>
                        </span>
                    </div>
                </td>
                <td class="px-1 py-1 align-top tabular-nums text-slate-700">{{ followers(row.followers) }}</td>
                <td class="px-1 py-1 align-top tabular-nums text-slate-700">
                    {{ row.no_posts_in_period ? '-' : pct(row.growth_pct) }}
                </td>
                <td class="px-1 py-1 align-top tabular-nums text-slate-700">
                    {{ row.no_posts_in_period ? '-' : num(row.posts_per_week) }}
                </td>
                <td class="px-1 py-1 align-top tabular-nums text-slate-700">
                    <span v-if="row.no_posts_in_period || row.er == null" class="text-slate-400" :title="row.er_reason || undefined">-</span>
                    <span v-else>{{ row.er.toFixed(2) }}%</span>
                </td>
                <td
                    class="whitespace-nowrap px-1 py-1 align-top text-slate-700"
                    :title="row.top_format ? postTypeLabel(row.top_format) : undefined"
                >
                    {{ row.no_posts_in_period || row.er == null ? '-' : formatCell(row.top_format) }}
                </td>
                <td class="px-1 py-1 align-top tabular-nums text-slate-700">
                    {{ row.no_posts_in_period ? '-' : row.winners }}
                </td>
            </tr>
        </tbody>
    </table>
</template>
