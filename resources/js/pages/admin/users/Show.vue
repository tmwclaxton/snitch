<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import VendorSpendStackedChart from '@/components/billing/VendorSpendStackedChart.vue';
import type { SpendGrain, SpendPoint } from '@/components/billing/VendorSpendStackedChart.vue';
import WeeklyVolumeChart from '@/components/dashboard/WeeklyVolumeChart.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPenceAsGbp } from '@/lib/money';
import { activity as adminActivity } from '@/routes/admin';
import { index as adminUsersIndex, show as adminUserShow } from '@/routes/admin/users';

type ActivityRow = {
    id: string;
    type: string;
    summary: string;
    occurred_at: string;
    amount_pence: number | null;
    ok: boolean | null;
};

type UserProfile = {
    id: number;
    name: string | null;
    email: string;
    created_at: string | null;
    created_via: string | null;
    claimed_at: string | null;
    plan_status: string;
    subscription: {
        status: string;
        plan_name: string | null;
        started_at: string | null;
        ends_at: string | null;
    };
    balance_pence: number;
    referral_code: string | null;
    referral_name: string | null;
    counts: {
        snitches: number;
        posts: number;
        analyses: number;
        mcp_calls: number;
        ledger_entries: number;
    };
};

const props = defineProps<{
    user: UserProfile;
    grain: SpendGrain;
    period_count: number;
    days: number;
    from: string;
    to: string;
    ledgerSeries: Array<{ week_start: string; label: string; count: number }>;
    mcpSeries: Array<{ week_start: string; label: string; count: number }>;
    spendSeries: {
        grain: SpendGrain;
        period_count: number;
        days: number;
        from: string;
        to: string;
        points: SpendPoint[];
    };
    activity: ActivityRow[];
}>();

defineOptions({
    layout: AppLayout,
});

const grainOptions: Array<{ value: SpendGrain; label: string }> = [
    { value: 'day', label: 'Daily' },
    { value: 'week', label: 'Weekly' },
    { value: 'month', label: 'Monthly' },
];

function grainHref(grain: SpendGrain): string {
    return adminUserShow.url(props.user.id, {
        query: grain === 'day' ? {} : { grain },
    });
}

const periodLabel = computed(() => {
    switch (props.grain) {
        case 'week':
            return `${props.period_count} wks`;
        case 'month':
            return `${props.period_count} mo`;
        default:
            return `${props.days}d`;
    }
});

function formatWhen(iso: string | null): string {
    if (!iso) {
        return '-';
    }

    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

function typeLabel(type: string): string {
    return type === 'mcp' ? 'MCP' : type === 'ledger' ? 'Ledger' : type;
}
</script>

<template>
    <div class="snitch-doc mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6">
        <Head :title="`Admin · ${user.email}`" />

        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="snitch-ink-label">Internal</p>
                <h1 class="font-display text-3xl text-snitch-ink">{{ user.email }}</h1>
                <p class="mt-1 text-sm text-snitch-ink/65">
                    {{ user.name || 'No name' }}
                    · joined {{ formatWhen(user.created_at) }}
                    · {{ from }} → {{ to }}
                </p>
                <nav class="mt-3 flex flex-wrap gap-3 text-sm">
                    <Link :href="adminUsersIndex()" class="underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink">
                        All users
                    </Link>
                    <Link :href="adminActivity()" class="underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink">
                        Platform activity
                    </Link>
                </nav>
            </div>
            <div
                class="snitch-seg flex flex-wrap gap-1"
                role="group"
                aria-label="Period grain"
            >
                <Link
                    v-for="option in grainOptions"
                    :key="option.value"
                    :href="grainHref(option.value)"
                    class="snitch-seg-item px-3 py-1.5 text-sm"
                    :class="grain === option.value ? 'snitch-seg-item-active' : ''"
                    preserve-scroll
                >
                    {{ option.label }}
                </Link>
            </div>
        </header>

        <section class="snitch-scrap grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="border border-snitch-ink/10 bg-snitch-paper/80 px-3 py-3">
                <p class="snitch-ink-label">Plan</p>
                <p class="mt-1 font-display text-xl text-snitch-ink">{{ user.plan_status }}</p>
                <p class="mt-0.5 text-xs text-snitch-ink/55">{{ user.subscription.plan_name || 'No subscription' }}</p>
            </div>
            <div class="border border-snitch-ink/10 bg-snitch-paper/80 px-3 py-3">
                <p class="snitch-ink-label">Balance</p>
                <p class="mt-1 font-display text-xl tabular-nums text-snitch-ink">{{ formatPenceAsGbp(user.balance_pence) }}</p>
            </div>
            <div class="border border-snitch-ink/10 bg-snitch-paper/80 px-3 py-3">
                <p class="snitch-ink-label">Snitches</p>
                <p class="mt-1 font-display text-xl tabular-nums text-snitch-ink">{{ user.counts.snitches }}</p>
                <p class="mt-0.5 text-xs text-snitch-ink/55">{{ user.counts.posts }} posts · {{ user.counts.analyses }} analyses</p>
            </div>
            <div class="border border-snitch-ink/10 bg-snitch-paper/80 px-3 py-3">
                <p class="snitch-ink-label">MCP / ledger</p>
                <p class="mt-1 font-display text-xl tabular-nums text-snitch-ink">{{ user.counts.mcp_calls }}</p>
                <p class="mt-0.5 text-xs text-snitch-ink/55">{{ user.counts.ledger_entries }} ledger rows</p>
            </div>
        </section>

        <section v-if="user.referral_code" class="snitch-scrap p-4 text-sm text-snitch-ink/70">
            Referral: <span class="font-mono text-snitch-ink">{{ user.referral_code }}</span>
            <span v-if="user.referral_name"> · {{ user.referral_name }}</span>
        </section>

        <section class="snitch-scrap space-y-3 p-4">
            <h2 class="font-display text-xl text-snitch-ink">Vendor spend</h2>
            <VendorSpendStackedChart
                :points="spendSeries.points"
                :days="spendSeries.days"
                :period-count="spendSeries.period_count"
                :grain="spendSeries.grain"
            />
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="snitch-scrap space-y-3 p-4">
                <WeeklyVolumeChart
                    :weeks="ledgerSeries"
                    title="Ledger entries"
                    :subtitle="`${ledgerSeries.reduce((sum, row) => sum + row.count, 0)} · ${periodLabel}`"
                    :dense-labels="grain === 'month' || grain === 'week'"
                />
            </section>
            <section class="snitch-scrap space-y-3 p-4">
                <WeeklyVolumeChart
                    :weeks="mcpSeries"
                    title="MCP calls"
                    :subtitle="`${mcpSeries.reduce((sum, row) => sum + row.count, 0)} · ${periodLabel}`"
                    :dense-labels="grain === 'month' || grain === 'week'"
                />
            </section>
        </div>

        <section class="snitch-scrap space-y-3 p-4">
            <h2 class="font-display text-xl text-snitch-ink">Activity log</h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-left text-sm">
                    <thead>
                        <tr class="snitch-ink-label border-b border-snitch-ink/10">
                            <th class="py-2 pr-3 font-normal">When</th>
                            <th class="py-2 pr-3 font-normal">Type</th>
                            <th class="py-2 pr-3 font-normal">Summary</th>
                            <th class="py-2 font-normal">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in activity"
                            :key="row.id"
                            class="border-b border-snitch-ink/8 align-top"
                        >
                            <td class="py-2 pr-3 whitespace-nowrap text-snitch-ink/70">{{ formatWhen(row.occurred_at) }}</td>
                            <td class="py-2 pr-3">
                                <span class="snitch-ink-label">{{ typeLabel(row.type) }}</span>
                                <span v-if="row.ok === false" class="ml-2 text-xs text-snitch-ink/45">failed</span>
                            </td>
                            <td class="py-2 pr-3 text-snitch-ink">{{ row.summary }}</td>
                            <td class="py-2 tabular-nums text-snitch-ink/80">
                                <span v-if="row.amount_pence != null">{{ formatPenceAsGbp(row.amount_pence) }}</span>
                                <span v-else>-</span>
                            </td>
                        </tr>
                        <tr v-if="!activity.length">
                            <td colspan="4" class="py-3 text-snitch-ink/55">No activity for this user yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
