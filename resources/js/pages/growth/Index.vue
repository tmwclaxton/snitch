<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { TrendingUp } from '@lucide/vue';
import { computed } from 'vue';
import GrowthController from '@/actions/App/Http/Controllers/GrowthController';
import MonthlyReportController from '@/actions/App/Http/Controllers/MonthlyReportController';
import GrowthLineChart from '@/components/growth/GrowthLineChart.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout,
});

type AccountOption = {
    id: number;
    handle: string;
    is_own_account: boolean;
    platform: string;
};

type Metrics = {
    period: string;
    snapshot_count: number;
    thin_data: boolean;
    note: string | null;
    charts: {
        followers: Array<{ name: string; points: Array<{ date: string; value: number | null }> }>;
        posts_per_week: Array<{ name: string; points: Array<{ date: string; value: number | null }> }>;
        engagement_rate: Array<{ name: string; points: Array<{ date: string; value: number | null }> }>;
        avg_multiplier: Array<{ name: string; points: Array<{ date: string; value: number | null }> }>;
    };
    accounts: Array<{
        handle: string;
        is_own_account: boolean;
        snapshot_count: number;
        summary: {
            followers: number | null;
            posts_per_week: number | null;
            engagement_rate: number | null;
            avg_multiplier: number | null;
        };
    }>;
};

const props = defineProps<{
    period: string;
    metrics: Metrics | null;
    accountOptions: AccountOption[];
    selectedAccounts: number[];
}>();

const periods = [
    { value: '30d', label: '30 days' },
    { value: '90d', label: '90 days' },
    { value: 'all', label: 'All time' },
];

const rivalOptions = computed(() =>
    props.accountOptions.filter((account) => !account.is_own_account),
);

function visit(next: { period?: string; accounts?: number[] }): void {
    router.get(
        GrowthController.index.url({
            query: {
                period: next.period ?? props.period,
                accounts: next.accounts ?? props.selectedAccounts,
            },
        }),
        {},
        { preserveState: true, replace: true },
    );
}

function toggleAccount(id: number): void {
    const set = new Set(props.selectedAccounts);

    if (set.has(id)) {
        set.delete(id);
    } else {
        set.add(id);
    }

    visit({ accounts: [...set] });
}
</script>

<template>
    <div class="snitch-app-shell mx-auto max-w-6xl space-y-4 px-3 py-4 sm:px-4">
        <Head title="Growth" />

        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="snitch-ink-label flex items-center gap-1.5">
                    <TrendingUp class="size-3.5" />
                    Growth
                </p>
                <h1 class="font-display text-2xl text-snitch-ink">
                    Own account vs peers
                </h1>
                <p class="mt-1 max-w-xl text-sm text-snitch-ink/65">
                    Followers, posting pace, engagement, and X× usual from weekly snapshots. Charts stay dense; empty weeks are skipped.
                </p>
            </div>
            <a
                :href="MonthlyReportController.show.url()"
                class="snitch-btn text-sm"
            >
                Monthly report
            </a>
        </header>

        <div class="snitch-filter-bar flex flex-wrap items-center gap-2">
            <label class="text-[11px] uppercase tracking-wide text-snitch-ink/55">
                Range
            </label>
            <div class="snitch-seg">
                <button
                    v-for="row in periods"
                    :key="row.value"
                    type="button"
                    class="snitch-seg-btn"
                    :class="{ 'is-active': period === row.value }"
                    @click="visit({ period: row.value })"
                >
                    {{ row.label }}
                </button>
            </div>
        </div>

        <div v-if="rivalOptions.length" class="flex flex-wrap gap-2">
            <button
                v-for="account in rivalOptions"
                :key="account.id"
                type="button"
                class="rounded border border-snitch-ink/20 bg-snitch-paper px-2 py-1 text-xs"
                :class="selectedAccounts.includes(account.id) || selectedAccounts.length === 0
                    ? 'border-snitch-ink bg-snitch-spot/30'
                    : 'opacity-60'"
                @click="toggleAccount(account.id)"
            >
                @{{ account.handle }}
            </button>
            <p class="w-full text-[11px] text-snitch-ink/50">
                Own account always included. Leave rivals unchecked to show all.
            </p>
        </div>

        <p
            v-if="metrics?.note"
            class="rounded border border-snitch-ink/15 bg-snitch-paper px-3 py-2 text-sm text-snitch-ink/70"
        >
            {{ metrics.note }}
            <span v-if="metrics.snapshot_count">
                ({{ metrics.snapshot_count }} snapshot{{ metrics.snapshot_count === 1 ? '' : 's' }})
            </span>
        </p>

        <div v-if="metrics" class="grid gap-3 lg:grid-cols-2">
            <GrowthLineChart
                title="Followers"
                :series="metrics.charts.followers"
                :note="metrics.thin_data ? metrics.note : null"
            />
            <GrowthLineChart
                title="Posts per week"
                :series="metrics.charts.posts_per_week"
            />
            <GrowthLineChart
                title="Engagement rate / follower"
                unit="%"
                :series="metrics.charts.engagement_rate"
            />
            <GrowthLineChart
                title="Average X× usual"
                unit="x"
                :series="metrics.charts.avg_multiplier"
            />
        </div>
    </div>
</template>
