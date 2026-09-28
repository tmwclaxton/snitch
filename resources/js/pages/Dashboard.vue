<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import { index as briefIndex } from '@/actions/App/Http/Controllers/BriefController';
import { index as competitors, show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import CtaLanguage from '@/components/CtaLanguage.vue';
import CaptionPanels from '@/components/dashboard/CaptionPanels.vue';
import CompareTable from '@/components/dashboard/CompareTable.vue';
import DashCard from '@/components/dashboard/DashCard.vue';
import DataNotes from '@/components/dashboard/DataNotes.vue';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import FollowerHistoryChart from '@/components/dashboard/FollowerHistoryChart.vue';
import FormatMixChart from '@/components/dashboard/FormatMixChart.vue';
import InsightList from '@/components/dashboard/InsightList.vue';
import PostingHeatmap from '@/components/dashboard/PostingHeatmap.vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import ThemeMatrix from '@/components/dashboard/ThemeMatrix.vue';
import WinnerCard from '@/components/dashboard/WinnerCard.vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import SnitchSkeleton from '@/components/SnitchSkeleton.vue';
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

type WinnerPost = {
    id: number;
    handle: string | null;
    avatar?: string | null;
    tracked_account_id?: number | null;
    is_own_account?: boolean;
    format: string;
    posted_at: string | null;
    pi: number;
    early?: boolean;
    likes: number | null;
    comments: number;
    views?: number | null;
    hook: string | null;
    tags?: string[];
    thumbnail_url: string | null;
    url: string | null;
    likes_hidden?: boolean;
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
    show_hidden_likes?: boolean;
    controls: {
        last_refreshed_at: string | null;
        next_refresh_at: string | null;
        has_non_instagram_trackers: boolean;
        ready?: boolean;
        hidden_likes_count?: number;
    };
    onboarding: Card<{
        hide?: boolean;
        steps: { key: string; label: string; done: boolean; suggestions?: string[] }[];
        note?: string | null;
    }>;
    rail?: { cells: { key: string; label: string; value: string; hint: string }[]; ready: boolean } | null;
    kpis?: Card<{ cards: Record<string, unknown>[] }> | null;
    insights?: Card<{ items: { category: string; text: string; score: number; n: number; links_to: string }[] }> | null;
    leaderboard?: Card<{ rows: Record<string, unknown>[] }> | null;
    winners?: Card<{
        winners: WinnerPost[];
        flops: WinnerPost[];
        hidden_likes_count?: number;
        hidden_included?: number;
        hidden_spotlight?: WinnerPost[];
    }> | null;
    format_lift?: Card<{ rows: Record<string, unknown>[]; peer_median_lift: Record<string, number | null> }> | null;
    captions?: Card<Record<string, unknown>> | null;
    themes?: Card<Record<string, unknown>> | null;
    data_notes?: Card<Record<string, unknown>> | null;
    activity?: {
        heatmap: { date: string; count: number }[];
        weekly: { week_start: string; label: string; count: number }[];
        by_time_of_day: { hour: number; label: string; count: number }[];
        window_start?: string | null;
        window_end?: string | null;
    } | null;
    follower_series?: { captured_on: string; label: string; followers: number }[] | null;
    growth_delta?: { followers: number; week_delta: number | null; week_pct: number | null } | null;
    weekly_brief?: {
        id: number;
        week_start: string | null;
        idea_count: number;
        hook: string | null;
        ideas?: { format: string; hook: string; slot: string }[];
    } | null;
    caption_intel?: {
        hashtags: { term: string; count: number }[];
        keywords: { term: string; count: number }[];
        ctas: { term: string; count: number; lines?: { text: string; count: number; post_id?: number | null }[] }[];
        cta_clicks: { posts_with_cta: number; posts: number };
        format_mix: { type: string; count: number }[];
    } | null;
}>();

const winnerTab = ref<'winners' | 'flops'>('winners');
const winnersExpanded = ref(false);

const showOnboarding = computed(
    () => props.onboarding.status === 'ok' && !(props.onboarding.data?.hide ?? false),
);

const hasInstagramSet = computed(() => props.rivals.length > 0 || props.own_account != null);

const hiddenLikesCount = computed(
    () => props.controls.hidden_likes_count
        ?? props.winners?.data?.hidden_likes_count
        ?? 0,
);

function refreshQuery(next: { accounts?: string[]; period?: number; hidden?: boolean }): void {
    const accounts = next.accounts ?? props.selected;
    const period = next.period ?? props.period;
    const hidden = next.hidden ?? props.show_hidden_likes ?? false;

    router.get(
        dashboard.url({
            query: {
                accounts: accounts.join(','),
                period: String(period),
                hidden: hidden ? '1' : '0',
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

const winnerList = computed(() => {
    const data = props.winners?.data;

    if (!data) {
        return [] as WinnerPost[];
    }

    return winnerTab.value === 'flops' ? (data.flops ?? []) : (data.winners ?? []);
});

const winnerItems = computed(() =>
    winnersExpanded.value ? winnerList.value.slice(0, 12) : winnerList.value.slice(0, 6),
);

const winnersHaveMore = computed(
    () => !winnersExpanded.value && winnerList.value.length > 6,
);

const hiddenInWinners = computed(
    () => winnerList.value.filter((post) => post.likes_hidden).length,
);

const hiddenSpotlight = computed(() => {
    if (!props.show_hidden_likes) {
        return [] as WinnerPost[];
    }

    if (hiddenInWinners.value > 0) {
        return [] as WinnerPost[];
    }

    return props.winners?.data?.hidden_spotlight ?? [];
});

const followerPoints = computed(() => props.follower_series ?? []);
const hasFollowerHistory = computed(() => followerPoints.value.length >= 2);
const showFollowerNote = computed(
    () => props.follower_series != null && !hasFollowerHistory.value,
);

const formatLifts = computed(() => props.format_lift?.data?.peer_median_lift ?? {});

const trackerIdsByHandle = computed(() => {
    const map: Record<string, number> = {};

    if (props.own_account) {
        map[props.own_account.handle.toLowerCase()] = props.own_account.id;
    }

    for (const rival of props.rivals) {
        map[rival.handle.toLowerCase()] = rival.id;
    }

    return map;
});
</script>

<template>
    <div class="min-h-full bg-white px-2 py-2 sm:px-3">
        <Head title="Dashboard" />

        <div class="mx-auto max-w-none space-y-2">
            <div
                v-if="weekly_brief"
                class="flex min-w-0 items-start gap-2 rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5"
            >
                <p class="hidden shrink-0 pt-0.5 text-xs font-semibold text-slate-800 sm:block">
                    Post this next
                </p>
                <div class="grid min-w-0 flex-1 grid-cols-1 gap-1.5 sm:grid-cols-3">
                    <Link
                        v-for="(idea, index) in (weekly_brief.ideas || []).slice(0, 3)"
                        :key="`${idea.slot}-${index}`"
                        :href="briefIndex.url()"
                        class="flex min-w-0 flex-col gap-0.5 rounded border border-slate-200 bg-white px-1.5 py-1 text-sm leading-snug text-slate-700 hover:border-slate-400"
                    >
                        <div class="flex items-center gap-1.5 text-xs">
                            <span class="shrink-0 rounded bg-slate-100 px-1 py-px font-medium uppercase tracking-wide text-slate-600">
                                {{ idea.format }}
                            </span>
                            <span class="shrink-0 tabular-nums text-slate-500">{{ idea.slot }}</span>
                        </div>
                        <span class="break-words text-slate-800">{{ idea.hook }}</span>
                    </Link>
                    <p
                        v-if="!(weekly_brief.ideas || []).length"
                        class="min-w-0 text-sm leading-snug text-slate-500 sm:col-span-3"
                    >
                        {{ weekly_brief.hook || 'Three weekly ideas from competitor winners' }}
                    </p>
                </div>
                <Link
                    :href="briefIndex.url()"
                    class="shrink-0 self-center rounded bg-slate-900 px-2 py-1 text-xs font-medium text-white hover:bg-slate-800"
                >
                    This week
                </Link>
            </div>

            <div class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-slate-200 pb-1.5">
                <h1 class="hidden shrink-0 text-sm font-semibold tracking-tight text-slate-900 sm:block">
                    Dashboard
                </h1>

                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1">
                    <div
                        v-if="own_account"
                        class="inline-flex h-6 items-center gap-1 rounded-full border border-slate-900 bg-slate-900 px-1.5 text-xs font-medium text-white"
                    >
                        <SnitchAvatar
                            :src="own_account.avatar"
                            :name="own_account.display_name"
                            :handle="own_account.handle"
                            size="sm"
                            class="!size-4 shrink-0"
                        />
                        <Link
                            :href="competitorShow.url(own_account.id)"
                            class="hover:underline"
                        >
                            You
                        </Link>
                    </div>
                    <div
                        v-for="rival in rivals"
                        :key="rival.id"
                        class="inline-flex h-6 items-center gap-1 rounded-full border px-1.5 text-xs font-medium"
                        :class="
                            selected.includes(rival.handle.toLowerCase())
                                ? 'border-slate-800 bg-slate-800 text-white'
                                : rival.no_posts_in_period
                                    ? 'border-slate-200 bg-slate-50 text-slate-400'
                                    : 'border-slate-200 bg-white text-slate-700'
                        "
                        :title="rival.no_posts_in_period ? 'No posts imported yet' : `@${rival.handle}`"
                    >
                        <button
                            type="button"
                            class="inline-flex items-center"
                            :aria-label="`Toggle ${rival.handle} in compare`"
                            @click="toggleAccount(rival.handle)"
                        >
                            <SnitchAvatar
                                :src="rival.avatar"
                                :name="rival.display_name"
                                :handle="rival.handle"
                                size="sm"
                                class="!size-4 shrink-0"
                            />
                        </button>
                        <Link
                            :href="competitorShow.url(rival.id)"
                            class="whitespace-nowrap hover:underline"
                        >
                            @{{ rival.handle }}
                        </Link>
                    </div>
                </div>

                <div class="ml-auto flex shrink-0 flex-wrap items-center gap-1">
                    <label class="group relative inline-flex h-6 cursor-pointer items-center gap-1 text-xs text-slate-600">
                        <input
                            type="checkbox"
                            class="peer sr-only"
                            :checked="!!show_hidden_likes"
                            aria-label="Show hidden-likes posts"
                            @change="refreshQuery({ hidden: !show_hidden_likes })"
                        >
                        <span
                            class="relative inline-flex h-3.5 w-6 shrink-0 items-center rounded-full bg-slate-200 transition-colors peer-checked:bg-slate-900 peer-focus-visible:ring-2 peer-focus-visible:ring-slate-400"
                            aria-hidden="true"
                        >
                            <span
                                class="ml-0.5 inline-block size-2.5 rounded-full bg-white transition-transform"
                                :class="show_hidden_likes ? 'translate-x-2.5' : 'translate-x-0'"
                            />
                        </span>
                        <span class="hidden whitespace-nowrap md:inline">Hidden likes</span>
                        <span
                            v-if="hiddenLikesCount > 0"
                            class="tabular-nums text-slate-400"
                            :title="show_hidden_likes
                                ? `${hiddenLikesCount} hidden-likes posts included in lists`
                                : `${hiddenLikesCount} hidden-likes posts excluded from averages`"
                        >
                            {{ show_hidden_likes ? `${hiddenLikesCount} in` : `${hiddenLikesCount}` }}
                        </span>
                        <span
                            class="relative inline-flex text-slate-400 group-hover:text-slate-700"
                            tabindex="0"
                            aria-label="About hidden-likes posts"
                        >
                            <Info class="h-3 w-3" />
                            <span
                                role="tooltip"
                                class="pointer-events-none absolute right-0 top-full z-20 mt-1.5 w-60 rounded border border-slate-200 bg-white p-2 text-left text-xs leading-snug text-slate-600 opacity-0 shadow-sm transition-opacity group-hover:opacity-100 group-focus-within:opacity-100"
                            >
                                Some accounts hide their like counts on Instagram. Those posts are left out of engagement averages and winner rankings by default. Turn this on to include them in post lists, ranked on comments and views.
                            </span>
                        </span>
                    </label>
                    <div class="flex items-center gap-0.5 rounded border border-slate-200 p-0.5">
                        <button
                            v-for="days in periods"
                            :key="days"
                            type="button"
                            class="rounded px-1.5 py-0.5 text-xs font-medium"
                            :class="period === days ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'"
                            @click="refreshQuery({ period: days })"
                        >
                            {{ days }}d
                        </button>
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
                            class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
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
                    id="rail"
                    class="snitch-dash-kpi-strip"
                >
                    <template v-if="kpis?.status === 'ok' && kpis.data?.cards?.length">
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
                    </template>
                    <template v-else>
                        <div
                            v-for="slot in 5"
                            :key="`kpi-pending-${slot}`"
                            class="min-w-0 border-r border-slate-200 px-2 py-1.5 last:border-r-0"
                            aria-hidden="true"
                        >
                            <span class="snitch-dash-rail-skel snitch-dash-rail-skel-label" />
                            <span class="snitch-dash-rail-skel snitch-dash-rail-skel-value" />
                        </div>
                    </template>
                </div>

                <div class="grid items-stretch gap-2 lg:grid-cols-2">
                    <!--
                      h-0 + min-h-full: row height comes from the right stack
                      (Leaderboard + Format mix). Insights fill that height with
                      up to 5 bullets (no inner scroll); Followers note pins to
                      the bottom when bullets fall short.
                    -->
                    <div class="flex min-h-[14rem] flex-col lg:h-0 lg:min-h-full">
                        <DashCard
                            class="flex h-full min-h-0 flex-1 flex-col overflow-hidden"
                            title="This week in 30 seconds"
                            why="Do-this-next lines from peer gaps for a busy organiser."
                            formula="score = |effect| × min(1, n/20); top 6, max 1 per category; n ≥ 5."
                            anchor="insights"
                        >
                            <div class="flex min-h-0 flex-1 flex-col gap-1">
                                <InsightList
                                    v-if="insights"
                                    class="min-h-0"
                                    :status="insights.status"
                                    :reason="insights.reason"
                                    :items="insights.data?.items"
                                    :tracker-ids="trackerIdsByHandle"
                                    :collapsed-count="5"
                                    fit-height
                                />
                                <SnitchSkeleton v-else variant="scrap" height="5rem" label="Loading insights" />

                                <p
                                    v-if="showFollowerNote"
                                    id="growth_series"
                                    class="mt-auto shrink-0 border-t border-slate-100 pt-1 text-sm leading-snug text-slate-600"
                                >
                                    <span class="font-medium text-slate-800">Followers</span>
                                    ·
                                    <template v-if="followerPoints.length === 1">
                                        {{ new Intl.NumberFormat('en-GB').format(followerPoints[0].followers) }}
                                        first snapshot only - growth chart after the next weekly count.
                                    </template>
                                    <template v-else>
                                        No follower snapshots yet.
                                    </template>
                                </p>
                                <p
                                    v-else-if="!follower_series"
                                    id="growth_series"
                                    class="mt-auto shrink-0"
                                >
                                    <SnitchSkeleton variant="scrap" height="1.25rem" label="Loading follower note" />
                                </p>
                            </div>
                        </DashCard>
                    </div>

                    <div class="flex min-w-0 flex-col gap-1.5">
                        <DashCard
                            title="Leaderboard"
                            why="One glance shows who is ahead, and on what."
                            formula="Engagement rate = median per-follower engagement. Hidden likes and all-zero samples show as -."
                            anchor="leaderboard"
                        >
                            <CompareTable
                                v-if="leaderboard"
                                :status="leaderboard.status"
                                :reason="leaderboard.reason"
                                :rows="(leaderboard.data?.rows as any) || []"
                            />
                            <SnitchSkeleton v-else variant="scrap" height="5rem" label="Loading leaderboard" />
                        </DashCard>

                        <div id="format_mix" class="snitch-scrap relative p-2">
                            <FormatMixChart
                                v-if="caption_intel"
                                class="snitch-dash-soft-in"
                                :formats="caption_intel.format_mix"
                                :lifts="formatLifts"
                            />
                            <SnitchSkeleton v-else variant="scrap" height="4rem" label="Loading format mix" />
                        </div>
                    </div>
                </div>

                <div class="grid items-stretch gap-2 lg:grid-cols-2">
                    <div class="min-h-[12rem] lg:h-0 lg:min-h-full">
                        <section id="activity" class="snitch-scrap snitch-dash-heatmap-panel relative flex h-full min-w-0 flex-col p-1.5">
                            <div class="mb-0 flex shrink-0 items-baseline justify-between gap-2">
                                <p class="snitch-ink-label">Posting heat map</p>
                                <p class="tabular-nums text-xs text-slate-500">16 wks</p>
                            </div>
                            <PostingHeatmap
                                v-if="activity"
                                class="snitch-heatmap--dash snitch-dash-soft-in min-h-0 flex-1"
                                :days="activity.heatmap"
                            />
                            <SnitchSkeleton v-else variant="scrap" height="6rem" label="Loading heat map" />
                        </section>
                    </div>

                    <section
                        id="caption_intel"
                        class="snitch-scrap snitch-dash-chip-stack flex min-w-0 flex-col gap-2 p-2"
                    >
                        <div class="min-w-0">
                            <p class="snitch-ink-label mb-1">Hashtags</p>
                            <div v-if="caption_intel" class="flex flex-wrap gap-1">
                                <span
                                    v-for="row in caption_intel.hashtags.slice(0, 14)"
                                    :key="`hash-${row.term}`"
                                    class="snitch-glance-tag"
                                >
                                    #{{ row.term }}
                                    <span class="tabular-nums text-slate-500">{{ row.count }}</span>
                                </span>
                                <p v-if="!caption_intel.hashtags.length" class="text-xs text-slate-500">None yet.</p>
                            </div>
                            <SnitchSkeleton v-else variant="scrap" height="2rem" label="Loading hashtags" />
                        </div>
                        <div class="min-w-0">
                            <CtaLanguage
                                v-if="caption_intel"
                                :ctas="caption_intel.ctas"
                            />
                            <SnitchSkeleton v-else variant="scrap" height="2rem" label="Loading CTA language" />
                        </div>
                    </section>
                </div>

                <div
                    v-if="hasFollowerHistory"
                    id="growth_series"
                    class="snitch-scrap relative max-h-44 p-2"
                >
                    <div class="mb-0.5 flex items-baseline justify-between gap-2">
                        <p class="snitch-ink-label">Followers</p>
                        <p
                            v-if="growth_delta?.week_delta != null"
                            class="tabular-nums text-xs text-slate-500"
                        >
                            {{ growth_delta.week_delta > 0 ? '+' : '' }}{{ growth_delta.week_delta }}
                            this week
                            <template v-if="growth_delta.week_pct != null">
                                ({{ growth_delta.week_pct > 0 ? '+' : '' }}{{ growth_delta.week_pct }}%)
                            </template>
                        </p>
                        <p v-else class="text-xs text-slate-400">
                            {{ followerPoints.length }} counts
                        </p>
                    </div>
                    <FollowerHistoryChart
                        class="snitch-dash-soft-in"
                        scope="corpus"
                        hide-title
                        compact
                        :points="followerPoints"
                    />
                </div>

                <DashCard
                    title="Winning posts"
                    why="Ready-made post ideas already proven with a similar audience (ranked by performance vs usual, not raw likes)."
                    formula="Performance vs usual = interactions ÷ median of the account's previous 30 posts. Winner ≥ 2.0×. Hidden-likes posts use comments+views when the toggle is on."
                    anchor="winners"
                >
                    <div class="mb-1 flex items-center gap-1.5">
                        <button
                            type="button"
                            class="rounded px-1 py-0.5 text-xs font-medium"
                            :class="winnerTab === 'winners' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'"
                            @click="winnerTab = 'winners'; winnersExpanded = false"
                        >
                            Winners
                        </button>
                        <button
                            type="button"
                            class="rounded px-1 py-0.5 text-xs font-medium"
                            :class="winnerTab === 'flops' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'"
                            @click="winnerTab = 'flops'; winnersExpanded = false"
                        >
                            Flops
                        </button>
                        <span
                            v-if="show_hidden_likes && (winners?.data?.hidden_included ?? 0) > 0"
                            class="text-xs text-amber-700"
                        >
                            {{ winners?.data?.hidden_included }} hidden-likes ranked on comments+views
                        </span>
                        <Link :href="winnersIndex.url()" class="ml-auto text-xs font-medium text-slate-500 hover:text-slate-800">
                            View all →
                        </Link>
                    </div>
                    <EmptyState
                        v-if="winners && (winners.status !== 'ok' || !winnerItems.length) && !hiddenSpotlight.length"
                        :reason="winners.reason || 'No posts in this tab.'"
                        compact
                    />
                    <div v-else-if="winnerItems.length" class="grid grid-cols-2 items-stretch gap-1.5 sm:grid-cols-3 xl:grid-cols-6">
                        <WinnerCard
                            v-for="post in winnerItems"
                            :key="String(post.id)"
                            :post="post as any"
                        />
                    </div>
                    <div
                        v-if="hiddenSpotlight.length"
                        class="mt-1.5"
                    >
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-amber-800/80">
                            Hidden-likes spotlight (comments+views)
                        </p>
                        <div class="grid grid-cols-2 items-stretch gap-1.5 sm:grid-cols-3 xl:grid-cols-6">
                            <WinnerCard
                                v-for="post in hiddenSpotlight"
                                :key="`hidden-${post.id}`"
                                :post="post as any"
                            />
                        </div>
                    </div>
                    <button
                        v-if="winnersHaveMore"
                        type="button"
                        class="mt-1 text-xs font-medium text-slate-600 underline-offset-2 hover:text-slate-900 hover:underline"
                        @click="winnersExpanded = true"
                    >
                        Show more ({{ winnerList.length - 6 }})
                    </button>
                    <SnitchSkeleton v-else-if="!winners" variant="scrap" height="6rem" label="Loading winners" />
                </DashCard>

                <DashCard
                    title="Captions and hooks"
                    why="Free changes to how posts are written."
                    formula="Length excludes trailing hashtags. Call to action via regex. Hooks from top performance winners."
                    anchor="captions"
                >
                    <CaptionPanels
                        v-if="captions"
                        :status="captions.status"
                        :reason="captions.reason"
                        :length-buckets="(captions.data?.length_buckets as any) || []"
                        :ctas="(captions.data?.ctas as any) || []"
                        :hashtag-buckets="(captions.data?.hashtag_buckets as any) || []"
                        :hooks="(captions.data?.hooks as any) || []"
                    />
                    <SnitchSkeleton v-else variant="scrap" height="5rem" label="Loading captions" />
                </DashCard>

                <DashCard
                    title="Topics and themes"
                    why="Topic gaps peers win with that you skip. Top 5 themes only."
                    formula="share = posts(theme)/posts · colour = median performance vs usual"
                    anchor="themes"
                >
                    <ThemeMatrix
                        v-if="themes"
                        :status="themes.status"
                        :reason="themes.reason"
                        :accounts="(themes.data?.accounts as any) || []"
                        :matrix="(themes.data?.matrix as any) || []"
                        :gaps="(themes.data?.gaps as any) || []"
                        :max-themes="5"
                    />
                    <SnitchSkeleton v-else variant="scrap" height="5rem" label="Loading themes" />
                </DashCard>

                <DashCard
                    title="Data notes"
                    why="Trust after the 102% engagement-rate incident."
                    formula="Posts analysed, range, excluded hidden likes. Reach/saves/shares are private."
                    anchor="data_notes"
                >
                    <DataNotes
                        v-if="data_notes"
                        :status="data_notes.status"
                        :reason="data_notes.reason"
                        :accounts="(data_notes.data?.accounts as any) || []"
                        :range="(data_notes.data?.range as string) || null"
                        :last-refreshed-at="(data_notes.data?.last_refreshed_at as string) || null"
                        :excluded-hidden-likes="Number(data_notes.data?.excluded_hidden_likes || 0)"
                        :note="(data_notes.data?.note as string) || null"
                        :format-uk="formatUk"
                    />
                    <SnitchSkeleton v-else variant="scrap" height="1.5rem" label="Loading data notes" />
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
                        You have marked
                        <Link
                            :href="competitorShow.url(own_account.id)"
                            class="font-medium text-slate-700 hover:underline"
                        >
                            @{{ own_account.handle }}
                        </Link>
                        as your account. Add rival Instagram handles on
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
