<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import CompareTable from '@/components/dashboard/CompareTable.vue';
import DashCard from '@/components/dashboard/DashCard.vue';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import InsightList from '@/components/dashboard/InsightList.vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import WinnerCard from '@/components/dashboard/WinnerCard.vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';

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

    return winnerTab.value === 'flops' ? (data.flops ?? []) : (data.winners ?? []);
});
</script>

<template>
    <div class="min-h-full bg-white px-4 py-6 sm:px-8">
        <Head title="Dashboard" />

        <div class="mx-auto max-w-[1200px] space-y-6">
            <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Dashboard</h1>
                    <p class="mt-1 text-sm text-slate-500">
                        How your Instagram competitors post - and what tends to work.
                    </p>
                </div>
                <div class="text-xs text-slate-500">
                    <div>Data refreshed {{ formatUk(controls.last_refreshed_at) }}</div>
                    <div>Next refresh {{ formatUk(controls.next_refresh_at) }}</div>
                </div>
            </div>

            <div
                v-if="hasInstagramSet"
                class="sticky top-0 z-10 -mx-4 flex flex-wrap items-center gap-3 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:-mx-0 sm:rounded-xl sm:border sm:px-4"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="own_account"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-full border border-slate-900 bg-slate-900 px-2.5 py-1 text-xs font-medium text-white"
                    >
                        <SnitchAvatar
                            :src="own_account.avatar"
                            :name="own_account.display_name"
                            :handle="own_account.handle"
                            size="sm"
                            class="!size-5"
                        />
                        You
                    </button>
                    <button
                        v-for="rival in rivals"
                        :key="rival.id"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs font-medium"
                        :class="
                            selected.includes(rival.handle.toLowerCase())
                                ? 'border-slate-800 bg-slate-800 text-white'
                                : rival.no_posts_in_period
                                    ? 'border-slate-200 bg-slate-50 text-slate-500'
                                    : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                        "
                        :title="rival.no_posts_in_period ? 'No posts imported yet' : undefined"
                        @click="toggleAccount(rival.handle)"
                    >
                        <SnitchAvatar
                            :src="rival.avatar"
                            :name="rival.display_name"
                            :handle="rival.handle"
                            size="sm"
                            class="!size-5"
                        />
                        @{{ rival.handle }}
                        <span v-if="rival.no_posts_in_period" class="text-[10px] opacity-80">no posts</span>
                    </button>
                </div>
                <div class="ml-auto flex items-center gap-1 rounded-lg border border-slate-200 p-0.5">
                    <button
                        v-for="days in periods"
                        :key="days"
                        type="button"
                        class="rounded-md px-2.5 py-1 text-xs font-medium"
                        :class="period === days ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'"
                        @click="refreshQuery({ period: days })"
                    >
                        {{ days }}d
                    </button>
                </div>
            </div>

            <DashCard
                v-if="showOnboarding"
                title="Get your dashboard ready"
                why="An empty dashboard with zeros is useless - complete these steps first."
                formula="Shown until at least one Instagram rival has 5+ posts in the selected period."
                anchor="onboarding"
            >
                <p v-if="onboarding.data?.note" class="mb-4 text-sm text-slate-600">
                    {{ onboarding.data.note }}
                </p>
                <ol class="space-y-3">
                    <li
                        v-for="(step, index) in onboarding.data?.steps || []"
                        :key="step.key"
                        class="flex gap-3 rounded-lg border border-slate-200 px-3 py-3"
                    >
                        <span
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                            :class="step.done ? 'bg-green-600 text-white' : 'bg-slate-100 text-slate-600'"
                        >
                            {{ step.done ? '✓' : index + 1 }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-slate-900">{{ step.label }}</div>
                            <div
                                v-if="step.key === 'rivals' && step.suggestions?.length"
                                class="mt-2 flex flex-wrap gap-1.5"
                            >
                                <span
                                    v-for="handle in step.suggestions"
                                    :key="handle"
                                    class="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
                                >
                                    @{{ handle }}
                                </span>
                            </div>
                        </div>
                    </li>
                </ol>
                <Link
                    v-if="rivals.length === 0"
                    :href="competitors()"
                    class="mt-4 inline-flex rounded-lg bg-[#F0C400] px-3 py-2 text-sm font-medium text-neutral-950 hover:opacity-90"
                >
                    Go to Tracking
                </Link>
            </DashCard>

            <template v-if="hasInstagramSet">
                <DashCard
                    title="This week in 30 seconds"
                    why="Turns the numbers into do-this-next for a busy organiser."
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
                    title="You vs peers"
                    why="Compare your KPIs against the peer median so the page is about your next move."
                    formula="Each card: You, peer median, gap. Sample size n < 5 shows Not enough posts yet."
                    anchor="kpis"
                >
                    <div
                        v-if="kpis.status === 'ok' && kpis.data?.cards?.length"
                        class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"
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
                    <EmptyState v-else :reason="kpis.reason" />
                </DashCard>

                <DashCard
                    title="Competitor leaderboard"
                    why="One glance shows who is ahead, and on what."
                    formula="ER = median per-follower engagement. Consistency = weeks with ≥1 post in last 8. Engagement share = interactions / set total."
                    anchor="leaderboard"
                >
                    <CompareTable
                        :status="leaderboard.status"
                        :reason="leaderboard.reason"
                        :rows="(leaderboard.data?.rows as any) || []"
                    />
                </DashCard>

                <DashCard
                    title="Winning posts"
                    why="Ready-made post ideas already proven with a similar audience."
                    formula="Performance Index = interactions ÷ median of the account's previous 30 posts. Winner ≥ 2.0×."
                    anchor="winners"
                >
                    <div class="mb-4 flex gap-2">
                        <button
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium"
                            :class="winnerTab === 'winners' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'"
                            @click="winnerTab = 'winners'"
                        >
                            Winners
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium"
                            :class="winnerTab === 'flops' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'"
                            @click="winnerTab = 'flops'"
                        >
                            Flops
                        </button>
                    </div>
                    <EmptyState
                        v-if="winners.status !== 'ok' || !winnerItems.length"
                        :reason="winners.reason || 'No posts in this tab.'"
                    />
                    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <WinnerCard
                            v-for="post in winnerItems"
                            :key="String(post.id)"
                            :post="post as any"
                        />
                    </div>
                </DashCard>
            </template>

            <div
                v-else-if="!showOnboarding"
                class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-12 text-center"
            >
                <h2 class="text-lg font-semibold text-slate-900">
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
                    class="mt-6 inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-800"
                >
                    Go to Tracking
                </Link>
            </div>
        </div>
    </div>
</template>
