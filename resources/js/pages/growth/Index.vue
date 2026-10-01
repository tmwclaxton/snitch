<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { TrendingUp } from '@lucide/vue';
import { computed } from 'vue';
import GrowthController from '@/actions/App/Http/Controllers/GrowthController';
import GrowthLineChart from '@/components/growth/GrowthLineChart.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { snitchAccountColour } from '@/lib/snitchTheme';

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
        followers: Array<{ name: string; is_own_account?: boolean; is_peer_median?: boolean; points: Array<{ date: string; value: number | null }> }>;
        posts_per_week: Array<{ name: string; is_own_account?: boolean; is_peer_median?: boolean; points: Array<{ date: string; value: number | null }> }>;
        engagement_rate: Array<{ name: string; is_own_account?: boolean; is_peer_median?: boolean; points: Array<{ date: string; value: number | null }> }>;
        avg_multiplier: Array<{ name: string; is_own_account?: boolean; is_peer_median?: boolean; points: Array<{ date: string; value: number | null }> }>;
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

function chipColour(handle: string): string {
    return snitchAccountColour(handle);
}

const showAllRivals = computed(() => props.selectedAccounts.length === 0);

function isRivalSelected(id: number): boolean {
    return showAllRivals.value || props.selectedAccounts.includes(id);
}

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
    <div class="snitch-app-shell w-full max-w-none space-y-4 px-3 py-4 sm:px-4">
        <Head title="Growth" />

        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="snitch-ink-label flex items-center gap-1.5">
                    <TrendingUp class="size-3.5" />
                    Growth
                </p>
                <h1 class="font-display text-2xl text-snitch-ink">
                    Own account vs rivals
                </h1>
                <p class="mt-1 max-w-xl text-sm text-snitch-ink/65">
                    How your account is trending against the accounts you track, week by week.
                </p>
            </div>
        </header>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium uppercase tracking-wide text-snitch-ink/55">
                Range
            </span>
            <div class="inline-flex items-center gap-0.5 rounded-md border border-snitch-ink/10 bg-snitch-fog p-0.5 dark:border-snitch-ink/28 dark:bg-[#1a1a1d]">
                <button
                    v-for="row in periods"
                    :key="row.value"
                    type="button"
                    class="snitch-choice rounded px-2.5 py-1 text-sm font-medium"
                    :class="period === row.value ? 'snitch-choice-active' : ''"
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
                class="snitch-choice h-7 rounded-full px-2 text-sm font-medium"
                :class="isRivalSelected(account.id) ? 'snitch-choice-active' : ''"
                @click="toggleAccount(account.id)"
            >
                <span
                    class="size-2.5 shrink-0 rounded-full"
                    :style="{ background: chipColour(account.handle) }"
                    aria-hidden="true"
                />
                @{{ account.handle }}
            </button>
            <p class="w-full text-xs text-snitch-ink/55">
                Own account always included. Leave rivals unchecked to show all.
            </p>
        </div>

        <div v-if="metrics" class="grid auto-rows-fr gap-3 lg:grid-cols-2">
            <GrowthLineChart
                title="Followers"
                :series="metrics.charts.followers"
                :table-fallback="metrics.thin_data"
                :note="metrics.thin_data ? metrics.note : null"
            />
            <GrowthLineChart
                title="Posts per week"
                :series="metrics.charts.posts_per_week"
            />
            <GrowthLineChart
                title="Engagement rate"
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
