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
    return value == null ? '-' : fmt.format(value);
}

function pct(value: number | null): string {
    return value == null ? '-' : `${value.toFixed(1)}%`;
}

function num(value: number | null): string {
    return value == null ? '-' : value.toFixed(1);
}
</script>

<template>
    <EmptyState v-if="status !== 'ok' || !rows?.length" :reason="reason" compact />
    <table v-else class="w-full table-fixed text-left text-[11px]">
        <colgroup>
            <col class="w-[30%]">
            <col class="w-[14%]">
            <col class="w-[12%]">
            <col class="w-[12%]">
            <col class="w-[12%]">
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
                class="h-8 border-b border-slate-100"
                :class="row.is_own_account ? 'bg-slate-50' : ''"
            >
                <td class="pr-1">
                    <div class="flex min-w-0 items-center gap-1" :title="row.row_note || undefined">
                        <SnitchAvatar
                            :src="row.avatar"
                            :name="row.display_name"
                            :handle="row.handle"
                            size="sm"
                            class="!size-4 shrink-0"
                        />
                        <span class="truncate font-medium text-slate-900">
                            <template v-if="row.is_own_account">You</template>
                            <template v-else>@{{ row.handle }}</template>
                        </span>
                    </div>
                </td>
                <td class="px-1 tabular-nums text-slate-700">{{ followers(row.followers) }}</td>
                <td class="px-1 tabular-nums text-slate-700">
                    {{ row.no_posts_in_period ? '-' : pct(row.growth_pct) }}
                </td>
                <td class="px-1 tabular-nums text-slate-700">
                    {{ row.no_posts_in_period ? '-' : num(row.posts_per_week) }}
                </td>
                <td class="px-1 tabular-nums text-slate-700">
                    <span v-if="row.no_posts_in_period || row.er == null" class="text-slate-400" :title="row.er_reason || undefined">-</span>
                    <span v-else>{{ row.er.toFixed(2) }}%</span>
                </td>
                <td class="truncate px-1 text-slate-700">
                    {{ row.no_posts_in_period || row.er == null ? '-' : row.top_format || '-' }}
                </td>
                <td class="px-1 tabular-nums text-slate-700">
                    {{ row.no_posts_in_period ? '-' : row.winners }}
                </td>
            </tr>
        </tbody>
    </table>
</template>
