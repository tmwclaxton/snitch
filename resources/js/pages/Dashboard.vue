<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import CompareTable from '@/components/dashboard/CompareTable.vue';
import DashCard from '@/components/dashboard/DashCard.vue';
import EfficiencyScatter from '@/components/dashboard/EfficiencyScatter.vue';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import FormatLift from '@/components/dashboard/FormatLift.vue';
import FormatMix from '@/components/dashboard/FormatMix.vue';
import GrowthChart from '@/components/dashboard/GrowthChart.vue';
import InsightList from '@/components/dashboard/InsightList.vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import WhenHeatmap from '@/components/dashboard/WhenHeatmap.vue';
import WinnerCard from '@/components/dashboard/WinnerCard.vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { index as winnersIndex } from '@/routes/winners';

defineOptions({
    layout: AppLayout,
});

type Card<T> = {
    status: 'ok' | 'insufficient' | 'empty';
    n: number;
    data: T | null;
    reason: string | null;
};

type Account = {
    id: number;
    handle: string;
    display_name: string | null;
    avatar: string | null;
    followers: number | null;
    is_own_account: boolean;
    posts_count: number;
    period_posts_count?: number;
    no_posts_in_period?: boolean;
};

const props = defineProps<{
    period: number;
    periods: number[];
    timezone: string;
    own_account: Account | null;
    rivals: Account[];
    selected: string[];
    max_compare: number;
    legacy_non_instagram_count?: number;
    controls: {
        last_refreshed_at: string | null;
        next_refresh_at: string | null;
        has_non_instagram_trackers: boolean;
        ready?: boolean;
    };
    onboarding: Card<{
        hide?: boolean;
        steps: { key: string; label: string; done: boolean; suggestions?: string[] }[];
        note?: string | null;
    }>;
    insights: Card<{ items: { category: string; text: string; score: number; n: number; links_to: string }[] }>;
    kpis: Card<{ cards: Record<string, unknown>[] }>;
    leaderboard: Card<{ rows: Record<string, unknown>[] }>;
    winners: Card<{ winners: Record<string, unknown>[]; flops: Record<string, unknown>[] }>;
    growth_series: Card<{ series: Record<string, unknown>[]; mode: string }>;
    efficiency: Card<{ points: Record<string, unknown>[]; median_x: number | null; median_y: number | null }>;
    format_mix: Card<{ rows: Record<string, unknown>[] }>;
    format_lift: Card<{ rows: Record<string, unknown>[]; peer_median_lift: Record<string, number | null> }>;
    heatmap: Card<{
        mode: 'pi' | 'count';
        days: string[];
        blocks: string[];
        cells: Record<string, unknown>[][];
        own_dots: { dow: number; block: number }[];
    }>;
}>();

const winnerTab = ref<'winners' | 'flops'>('winners');

const showOnboarding = computed(
    () => props.onboarding.status === 'ok' && !(props.onboarding.data?.hide ?? false),
);

const hasInstagramSet = computed(() => props.rivals.length > 0 || props.own_account != null);

function refreshQuery(next: { accounts?: string[]; period?: number }): void {
    const accounts = next.accounts ?? props.selected;
    const period = next.period ?? props.period;

    router.get(
        dashboard.url({
            query: {
                accounts: accounts.join(','),
                period: String(period),
            },
        }),
        {},
        { preserveState: true, replace: true },
    );
}

function toggleAccount(handle: string): void {
    const key = handle.toLowerCase();
    const current = [...props.selected];
    const exists = current.includes(key);

    if (exists && current.length === 1) {
        return;
    }

    const next = exists
        ? current.filter((value) => value !== key)
        : current.length >= props.max_compare
            ? current
            : [...current, key];

    refreshQuery({ accounts: next });
}

function formatUk(iso: string | null): string {
    if (!iso) {
        return '-';
    }

    try {
        return new Intl.DateTimeFormat('en-GB', {
            timeZone: props.timezone || 'Europe/London',
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

const winnerItems = computed(() => {
    const data = props.winners.data;

    if (!data) {
        return [];
    }

    const list = winnerTab.value === 'flops' ? (data.flops ?? []) : (data.winners ?? []);

    return list.slice(0, 6);
});
</script>

<template>
    <div class="min-h-full bg-white px-3 py-3 sm:px-5">
        <Head title="Dashboard" />

        <div class="mx-auto max-w-[1400px] space-y-2.5">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-slate-200 pb-2">
                <h1 class="shrink-0 text-base font-semibold tracking-tight text-slate-900">Dashboard</h1>

                <button
                    v-if="own_account"
                    type="button"
                    class="inline-flex h-7 items-center gap-1 rounded-full border border-slate-900 bg-slate-900 px-1.5 text-[11px] font-medium text-white"
                >
                    <SnitchAvatar
                        :src="own_account.avatar"
                        :name="own_account.display_name"
                        :handle="own_account.handle"
                        size="sm"
                        class="!size-4"
                    />
                    You
                </button>
                <button
                    v-for="rival in rivals"
                    :key="rival.id"
                    type="button"
                    class="inline-flex h-7 max-w-[9.5rem] items-center gap-1 rounded-full border px-1.5 text-[11px] font-medium"
                    :class="
                        selected.includes(rival.handle.toLowerCase())
                            ? 'border-slate-800 bg-slate-800 text-white'
                            : rival.no_posts_in_period
                                ? 'border-slate-200 bg-slate-50 text-slate-400'
                                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                    "
                    :title="rival.no_posts_in_period ? 'No posts imported yet' : `@${rival.handle}`"
                    @click="toggleAccount(rival.handle)"
                >
                    <SnitchAvatar
                        :src="rival.avatar"
                        :name="rival.display_name"
                        :handle="rival.handle"
                        size="sm"
                        class="!size-4"
                    />
                    <span class="truncate">@{{ rival.handle }}</span>
                </button>

                <div class="ml-auto flex items-center gap-2">
                    <div class="flex items-center gap-0.5 rounded border border-slate-200 p-0.5">
                        <button
                            v-for="days in periods"
                            :key="days"
                            type="button"
                            class="rounded px-1.5 py-0.5 text-[11px] font-medium"
                            :class="period === days ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'"
                            @click="refreshQuery({ period: days })"
                        >
                            {{ days }}d
                        </button>
                    </div>
                    <div class="hidden text-[10px] text-slate-400 lg:block">
                        {{ formatUk(controls.last_refreshed_at) }}
                    </div>
                </div>
            </div>

            <DashCard
                v-if="showOnboarding"
                title="Get ready"
                why="Complete these steps before the graphs mean anything."
                formula="Shown until at least one Instagram rival has 5+ posts in the selected period."
                anchor="onboarding"
            >
                <p v-if="onboarding.data?.note" class="mb-2 text-xs text-slate-600">{{ onboarding.data.note }}</p>
                <ol class="grid gap-2 sm:grid-cols-3">
                    <li
                        v-for="(step, index) in onboarding.data?.steps || []"
                        :key="step.key"
                        class="flex gap-2 rounded-lg border border-slate-200 px-2.5 py-2"
                    >
                        <span
                            class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold"
                            :class="step.done ? 'bg-green-600 text-white' : 'bg-slate-100 text-slate-600'"
                        >
                            {{ step.done ? '✓' : index + 1 }}
                        </span>
                        <div class="min-w-0 text-xs font-medium text-slate-900">{{ step.label }}</div>
                    </li>
                </ol>
                <Link
                    v-if="rivals.length === 0"
                    :href="competitors()"
                    class="mt-3 inline-flex rounded-md bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-slate-800"
                >
                    Go to Tracking
                </Link>
            </DashCard>

            <template v-if="hasInstagramSet">
                <div
                    v-if="kpis.status === 'ok' && kpis.data?.cards?.length"
                    id="kpis"
                    class="grid grid-cols-2 gap-2 xl:grid-cols-5"
                >
                    <StatCard
                        v-for="card in kpis.data.cards"
                        :key="String(card.key)"
                        :label="String(card.label)"
                        :why="String(card.why)"
                        :formula="String(card.formula)"
                        :status="String(card.status)"
                        :reason="(card.reason as string | null) || null"
                        :you="(card.you as number | null) ?? null"
                        :you-display="(card.you_display as number | string | null) ?? null"
                        :peer-median="(card.peer_median as number | null) ?? null"
                        :gap="(card.gap as any) ?? null"
                        :unit="String(card.unit)"
                    />
                </div>
                <EmptyState v-else :reason="kpis.reason" compact />

                <div class="grid gap-2.5 xl:grid-cols-5">
                    <DashCard
                        class="xl:col-span-2"
                        title="This week in 30 seconds"
                        why="Do-this-next lines from peer gaps."
                        formula="score = |effect| × min(1, n/20); top 5, max 1 per category; n ≥ 5."
                        anchor="insights"
                    >
                        <InsightList
                            :status="insights.status"
                            :reason="insights.reason"
                            :items="insights.data?.items"
                        />
                    </DashCard>

                    <DashCard
                        class="xl:col-span-3"
                        title="Leaderboard"
                        why="Who is ahead, and on what."
                        formula="ER = median per-follower engagement. Consistency = weeks with ≥1 post in last 8."
                        anchor="leaderboard"
                    >
                        <CompareTable
                            :status="leaderboard.status"
                            :reason="leaderboard.reason"
                            :rows="(leaderboard.data?.rows as any) || []"
                        />
                    </DashCard>
                </div>

                <DashCard
                    title="Winning posts"
                    why="Ideas already proven with a similar audience (ranked by PI, not raw likes)."
                    formula="PI = interactions ÷ median of the account's previous 30 posts. Winner ≥ 2.0×."
                    anchor="winners"
                >
                    <div class="mb-1.5 flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded px-2 py-0.5 text-[11px] font-medium"
                            :class="winnerTab === 'winners' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'"
                            @click="winnerTab = 'winners'"
                        >
                            Winners
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-0.5 text-[11px] font-medium"
                            :class="winnerTab === 'flops' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'"
                            @click="winnerTab = 'flops'"
                        >
                            Flops
                        </button>
                        <Link :href="winnersIndex.url()" class="ml-auto text-[11px] font-medium text-slate-500 hover:text-slate-800">
                            View all →
                        </Link>
                    </div>
                    <EmptyState
                        v-if="winners.status !== 'ok' || !winnerItems.length"
                        :reason="winners.reason || 'No posts in this tab.'"
                        compact
                    />
                    <div v-else class="grid grid-cols-3 gap-1.5 sm:grid-cols-6">
                        <WinnerCard
                            v-for="post in winnerItems"
                            :key="String(post.id)"
                            :post="post as any"
                            compact
                        />
                    </div>
                </DashCard>

                <div class="grid gap-2.5 md:grid-cols-2 xl:grid-cols-4">
                    <DashCard
                        title="Follower growth"
                        why="Spikes show when a peer did something that worked."
                        formula="% change since first snapshot in period. Real snapshots only - never fabricated."
                        anchor="growth_series"
                    >
                        <GrowthChart
                            :status="growth_series.status"
                            :reason="growth_series.reason"
                            :series="(growth_series.data?.series as any) || []"
                        />
                    </DashCard>

                    <DashCard
                        title="Efficiency map"
                        why="Volume vs quality - post more, or post better?"
                        formula="x = posts/week · y = median ER/follower · bubble = followers"
                        anchor="efficiency"
                    >
                        <EfficiencyScatter
                            :status="efficiency.status"
                            :reason="efficiency.reason"
                            :points="(efficiency.data?.points as any) || []"
                            :median-x="efficiency.data?.median_x ?? null"
                            :median-y="efficiency.data?.median_y ?? null"
                        />
                    </DashCard>

                    <DashCard
                        title="Format mix"
                        why="What peers publish."
                        formula="share of posts by type in the period"
                        anchor="format_mix"
                    >
                        <FormatMix
                            :status="format_mix.status"
                            :reason="format_mix.reason"
                            :rows="(format_mix.data?.rows as any) || []"
                        />
                    </DashCard>

                    <DashCard
                        title="Format lift"
                        why="Which formats beat each account's usual."
                        formula="median ER(format) ÷ median ER(account); need n≥3 per format"
                        anchor="format_lift"
                    >
                        <FormatLift
                            :status="format_lift.status"
                            :reason="format_lift.reason"
                            :rows="(format_lift.data?.rows as any) || []"
                            :peer-median-lift="format_lift.data?.peer_median_lift || {}"
                        />
                    </DashCard>
                </div>

                <DashCard
                    title="When posts do best"
                    why="Peer timing evidence in Europe/London, not generic '5 AM' advice."
                    formula="Cell = median Performance Index. Grey when n < 3. Your posts as dots."
                    anchor="heatmap"
                >
                    <WhenHeatmap
                        :status="heatmap.status"
                        :reason="heatmap.reason"
                        :days="heatmap.data?.days"
                        :blocks="heatmap.data?.blocks"
                        :cells="(heatmap.data?.cells as any) || []"
                        :own-dots="heatmap.data?.own_dots || []"
                        :mode="heatmap.data?.mode || 'pi'"
                    />
                </DashCard>
            </template>

            <div
                v-else-if="!showOnboarding"
                class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center"
            >
                <h2 class="text-base font-semibold text-slate-900">
                    {{ own_account ? 'No rivals to compare yet' : 'No Instagram competitors yet' }}
                </h2>
                <p class="mt-2 text-sm text-slate-500">
                    <template v-if="own_account">
                        You have marked @{{ own_account.handle }} as your account. Add rival Instagram handles on
                        Tracking to see the gap.
                    </template>
                    <template v-else-if="(legacy_non_instagram_count ?? 0) > 0">
                        Tracking lists {{ legacy_non_instagram_count }} account{{
                            legacy_non_instagram_count === 1 ? '' : 's'
                        }}
                        from older platforms. This dashboard only compares Instagram rivals - add Instagram handles on
                        Tracking to populate it.
                    </template>
                    <template v-else>
                        Add Instagram competitor handles on Tracking to populate this dashboard. Only Instagram accounts appear here.
                    </template>
                </p>
                <Link
                    :href="competitors()"
                    class="mt-4 inline-block rounded-lg bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-800"
                >
                    Go to Tracking
                </Link>
            </div>
        </div>
    </div>
</template>
