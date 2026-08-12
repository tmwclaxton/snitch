<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SpendGrain } from '@/components/billing/VendorSpendStackedChart.vue';
import WeeklyVolumeChart from '@/components/dashboard/WeeklyVolumeChart.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPenceAsGbp } from '@/lib/money';
import { activity as adminActivity, overview as adminOverview } from '@/routes/admin';
import { index as adminUsersIndex, show as adminUserShow } from '@/routes/admin/users';

type ActivityEvent = {
    id: string;
    type: string;
    summary: string;
    occurred_at: string;
    user_id: number | null;
    user_email: string | null;
    ok: boolean | null;
};

type Kpis = {
    signups: number;
    ledger_entries: number;
    ledger_spend_pence: number;
    mcp_calls: number;
    analyses: number;
};

const props = defineProps<{
    grain: SpendGrain;
    period_count: number;
    days: number;
    from: string;
    to: string;
    kpis: Kpis;
    signupsSeries: Array<{ week_start: string; label: string; count: number }>;
    ledgerSeries: Array<{ week_start: string; label: string; count: number }>;
    mcpSeries: Array<{ week_start: string; label: string; count: number }>;
    analysesSeries: Array<{ week_start: string; label: string; count: number }>;
    recentEvents: ActivityEvent[];
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
    return adminActivity.url({
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

function formatWhen(iso: string): string {
    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

function typeLabel(type: string): string {
    switch (type) {
        case 'ledger':
            return 'Ledger';
        case 'mcp':
            return 'MCP';
        case 'signup':
            return 'Signup';
        case 'analysis':
            return 'Analysis';
        default:
            return type;
    }
}
</script>

<template>
    <div class="snitch-doc mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6">
        <Head title="Admin activity" />

        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="snitch-ink-label">Internal</p>
                <h1 class="font-display text-3xl text-snitch-ink">Platform activity</h1>
                <p class="mt-1 max-w-xl text-sm text-snitch-ink/65">
                    Signups, ledger entries, MCP calls, and analyses from existing first-party tables.
                    {{ from }} → {{ to }}.
                </p>
                <nav class="mt-3 flex flex-wrap gap-3 text-sm">
                    <Link :href="adminOverview()" class="underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink">
                        Overview
                    </Link>
                    <Link :href="adminUsersIndex()" class="underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink">
                        Users
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

        <section class="snitch-scrap grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div
                v-for="item in [
                    { label: 'Signups', value: String(kpis.signups), hint: periodLabel },
                    { label: 'Ledger entries', value: String(kpis.ledger_entries), hint: 'all types' },
                    { label: 'Spend', value: formatPenceAsGbp(kpis.ledger_spend_pence), hint: 'vendor charges' },
                    { label: 'MCP calls', value: String(kpis.mcp_calls), hint: periodLabel },
                    { label: 'Analyses', value: String(kpis.analyses), hint: periodLabel },
                ]"
                :key="item.label"
                class="border border-snitch-ink/10 bg-snitch-paper/80 px-3 py-3"
            >
                <p class="snitch-ink-label">{{ item.label }}</p>
                <p class="mt-1 font-display text-2xl tabular-nums text-snitch-ink">{{ item.value }}</p>
                <p class="mt-0.5 text-xs text-snitch-ink/55">{{ item.hint }}</p>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="snitch-scrap space-y-3 p-4">
                <WeeklyVolumeChart
                    :weeks="signupsSeries"
                    title="Signups"
                    :subtitle="`${signupsSeries.reduce((sum, row) => sum + row.count, 0)} · ${periodLabel}`"
                    :dense-labels="grain === 'month' || grain === 'week'"
                />
            </section>
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
                    title="MCP tool calls"
                    :subtitle="`${mcpSeries.reduce((sum, row) => sum + row.count, 0)} · ${periodLabel}`"
                    :dense-labels="grain === 'month' || grain === 'week'"
                />
            </section>
            <section class="snitch-scrap space-y-3 p-4">
                <WeeklyVolumeChart
                    :weeks="analysesSeries"
                    title="Post analyses"
                    :subtitle="`${analysesSeries.reduce((sum, row) => sum + row.count, 0)} · ${periodLabel}`"
                    :dense-labels="grain === 'month' || grain === 'week'"
                />
            </section>
        </div>

        <section class="snitch-scrap space-y-3 p-4">
            <h2 class="font-display text-xl text-snitch-ink">Recent activity</h2>
            <p class="text-xs text-snitch-ink/55">
                Merged from credit ledger, MCP invocations, signups, and analyses. No separate audit table.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead>
                        <tr class="snitch-ink-label border-b border-snitch-ink/10">
                            <th class="py-2 pr-3 font-normal">When</th>
                            <th class="py-2 pr-3 font-normal">Type</th>
                            <th class="py-2 pr-3 font-normal">Summary</th>
                            <th class="py-2 font-normal">User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in recentEvents"
                            :key="row.id"
                            class="border-b border-snitch-ink/8 align-top"
                        >
                            <td class="py-2 pr-3 whitespace-nowrap text-snitch-ink/70">{{ formatWhen(row.occurred_at) }}</td>
                            <td class="py-2 pr-3">
                                <span class="snitch-ink-label">{{ typeLabel(row.type) }}</span>
                                <span
                                    v-if="row.ok === false"
                                    class="ml-2 text-xs text-snitch-ink/45"
                                >failed</span>
                            </td>
                            <td class="py-2 pr-3 text-snitch-ink">{{ row.summary }}</td>
                            <td class="py-2">
                                <Link
                                    v-if="row.user_id"
                                    :href="adminUserShow(row.user_id)"
                                    class="underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink"
                                >
                                    {{ row.user_email || `#${row.user_id}` }}
                                </Link>
                                <span v-else class="text-snitch-ink/45">-</span>
                            </td>
                        </tr>
                        <tr v-if="!recentEvents.length">
                            <td colspan="4" class="py-3 text-snitch-ink/55">No activity logged yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
