<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import { index as competitors, show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import CtaLanguage from '@/components/CtaLanguage.vue';
import ActionList from '@/components/dashboard/ActionList.vue';
import AttentionBars from '@/components/dashboard/AttentionBars.vue';
import CaptionPanels from '@/components/dashboard/CaptionPanels.vue';
import CompareTable from '@/components/dashboard/CompareTable.vue';
import DashCard from '@/components/dashboard/DashCard.vue';
import DataNotes from '@/components/dashboard/DataNotes.vue';
import EfficiencyScatter from '@/components/dashboard/EfficiencyScatter.vue';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import FollowerHistoryChart from '@/components/dashboard/FollowerHistoryChart.vue';
import FormatLift from '@/components/dashboard/FormatLift.vue';
import FormatMixChart from '@/components/dashboard/FormatMixChart.vue';
import InsightList from '@/components/dashboard/InsightList.vue';
import PostingHeatmap from '@/components/dashboard/PostingHeatmap.vue';
import ThemeMatrix from '@/components/dashboard/ThemeMatrix.vue';
import TimeOfDayChart from '@/components/dashboard/TimeOfDayChart.vue';
import WeeklyMultiples from '@/components/dashboard/WeeklyMultiples.vue';
import WeeklyVolumeChart from '@/components/dashboard/WeeklyVolumeChart.vue';
import WhenHeatmap from '@/components/dashboard/WhenHeatmap.vue';
import FeedContactCell from '@/components/FeedContactCell.vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import SnitchSkeleton from '@/components/SnitchSkeleton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { index as feedIndex } from '@/routes/feed';
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

type RailCell = {
    key: string;
    label: string;
    value: string;
    hint: string;
    href: string | null;
    you: number | null;
    peer: number | null;
};

type RecentPost = {
    id: number;
    platform: string;
    type: string;
    url: string | null;
    caption?: string | null;
    media_url: string | null;
    cover_url?: string | null;
    media_availability?: string | null;
    metrics?: Record<string, unknown> | null;
    tracked_account?: { id?: number; handle: string; display_name?: string | null } | null;
    analysis?: {
        status: string;
        hook?: string | null;
        concept?: string | null;
        topics?: string[] | null;
        custom_tags?: string[] | null;
        term_labels?: { dimension: string; slug: string; label: string; section?: string | null }[] | null;
    } | null;
    winner_insight?: { score: number } | null;
};

type WinnerPost = {
    id: number;
    handle: string | null;
    tracked_account_id?: number | null;
    pi: number;
    hook: string | null;
    thumbnail_url: string | null;
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
    };
    onboarding: Card<{
        hide?: boolean;
        steps: { key: string; label: string; done: boolean; suggestions?: string[] }[];
        note?: string | null;
    }>;
    rail?: { cells: RailCell[]; ready: boolean } | null;
    kpis?: Card<{ cards: Record<string, unknown>[] }> | null;
    insights?: Card<{ items: { category: string; text: string; score: number; n: number; links_to: string }[] }> | null;
    leaderboard?: Card<{ rows: Record<string, unknown>[] }> | null;
    winners?: Card<{ winners: WinnerPost[]; flops: WinnerPost[] }> | null;
    growth_series?: Card<{ series: Record<string, unknown>[]; mode: string }> | null;
    efficiency?: Card<{ points: Record<string, unknown>[]; median_x: number | null; median_y: number | null }> | null;
    format_mix?: Card<{ rows: Record<string, unknown>[] }> | null;
    format_lift?: Card<{ rows: Record<string, unknown>[]; peer_median_lift: Record<string, number | null> }> | null;
    heatmap?: Card<{
        mode: 'pi' | 'count';
        days: string[];
        blocks: string[];
        cells: Record<string, unknown>[][];
        own_dots: { dow: number; block: number }[];
    }> | null;
    captions?: Card<Record<string, unknown>> | null;
    themes?: Card<Record<string, unknown>> | null;
    weekly?: Card<Record<string, unknown>> | null;
    attention?: Card<Record<string, unknown>> | null;
    actions?: Card<{ items: { text: string; links_to: string; n: number }[]; peer_only?: boolean }> | null;
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
    recent_posts?: RecentPost[] | null;
    caption_intel?: {
        hashtags: { term: string; count: number }[];
        keywords: { term: string; count: number }[];
        ctas: { term: string; count: number; lines?: { text: string; count: number; post_id?: number | null }[] }[];
        cta_clicks: { posts_with_cta: number; posts: number };
        format_mix: { type: string; count: number }[];
    } | null;
}>();

const winnerTab = ref<'winners' | 'flops'>('winners');
const showHiddenTip = ref(false);

const showOnboarding = computed(
    () => props.onboarding.status === 'ok' && !(props.onboarding.data?.hide ?? false),
);

const hasInstagramSet = computed(() => props.rivals.length > 0 || props.own_account != null);
const panelReady = computed(() => props.activity != null && props.caption_intel != null);

const railHref = (key: string | null): string | null => {
    if (key === 'tracking') {
        return competitors.url();
    }

    if (key === 'feed') {
        return feedIndex.url();
    }

    if (key === 'winners') {
        return winnersIndex.url();
    }

    return null;
};

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

function accountHrefFor(post: RecentPost): string | null {
    const id = post.tracked_account?.id;

    return id ? competitorShow.url(id) : null;
}

const winnerItems = computed(() => {
    const data = props.winners?.data;

    if (!data) {
        return [];
    }

    const list = winnerTab.value === 'flops' ? (data.flops ?? []) : (data.winners ?? []);

    return list.slice(0, 8);
});

const weeklyPostTotal = computed(() =>
    (props.activity?.weekly ?? []).reduce((sum, row) => sum + row.count, 0),
);

const timeOfDayTotal = computed(() =>
    (props.activity?.by_time_of_day ?? []).reduce((sum, row) => sum + row.count, 0),
);
</script>

<template>
    <div class="min-h-full bg-white px-2 py-2 sm:px-3">
        <Head title="Dashboard" />

        <div class="mx-auto max-w-none space-y-3">
            <div class="flex flex-nowrap items-center gap-x-1.5 overflow-x-auto border-b border-slate-200 pb-1.5">
                <h1 class="shrink-0 text-sm font-semibold tracking-tight text-slate-900">Dashboard</h1>

                <button
                    v-if="own_account"
                    type="button"
                    class="inline-flex h-6 shrink-0 items-center gap-1 rounded-full border border-slate-900 bg-slate-900 px-1.5 text-[10px] font-medium text-white"
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
                    class="inline-flex h-6 max-w-[8.5rem] shrink-0 items-center gap-1 rounded-full border px-1.5 text-[10px] font-medium"
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
                    <label class="inline-flex items-center gap-1 text-[10px] text-slate-500" title="Include posts with hidden likes in winner lists">
                        <input
                            type="checkbox"
                            class="size-3 rounded border-slate-300"
                            :checked="!!show_hidden_likes"
                            @change="refreshQuery({ hidden: !show_hidden_likes })"
                        >
                        Hidden likes
                        <button
                            type="button"
                            class="relative rounded p-0.5 text-slate-400 hover:text-slate-700"
                            @click.prevent="showHiddenTip = !showHiddenTip"
                            @blur="showHiddenTip = false"
                        >
                            <Info class="h-3 w-3" />
                            <span
                                v-if="showHiddenTip"
                                class="absolute right-0 z-20 mt-2 w-56 rounded border border-slate-200 bg-white p-2 text-left text-[10px] leading-snug text-slate-600 shadow-sm"
                            >
                                Some accounts hide like counts. Those posts are left out of engagement averages and winner rankings by default. Turn this on to include them in post lists, ranked on comments and views.
                            </span>
                        </button>
                    </label>
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
                <div id="rail" class="snitch-dash-rail">
                    <template v-if="rail?.cells?.length">
                        <component
                            :is="railHref(cell.href) ? Link : 'div'"
                            v-for="cell in rail.cells"
                            :key="cell.key"
                            :href="railHref(cell.href) || undefined"
                            class="snitch-dash-rail-item"
                        >
                            <span class="snitch-ink-label">{{ cell.label }}</span>
                            <span class="text-2xl font-semibold tabular-nums tracking-tight text-slate-900">{{ cell.value }}</span>
                            <span class="snitch-dash-rail-hint">{{ cell.hint }}</span>
                        </component>
                    </template>
                    <template v-else>
                        <div
                            v-for="slot in 9"
                            :key="`rail-pending-${slot}`"
                            class="snitch-dash-rail-item snitch-dash-rail-item-pending"
                            aria-hidden="true"
                        >
                            <span class="snitch-dash-rail-skel snitch-dash-rail-skel-label" />
                            <span class="snitch-dash-rail-skel snitch-dash-rail-skel-value" />
                        </div>
                    </template>
                </div>

                <section id="activity" class="snitch-dash-charts">
                    <div class="grid items-stretch gap-3 lg:grid-cols-[minmax(0,1.45fr)_minmax(16rem,0.8fr)]">
                        <div class="snitch-scrap snitch-dash-chart-slot relative flex h-full flex-col p-3">
                            <div class="mb-2 flex items-baseline justify-between gap-2">
                                <p class="snitch-ink-label">Posting heat map</p>
                                <p class="tabular-nums text-[10px] text-slate-500">16 wks</p>
                            </div>
                            <PostingHeatmap
                                v-if="activity"
                                class="snitch-dash-soft-in"
                                :days="activity.heatmap"
                            />
                            <SnitchSkeleton v-else variant="scrap" height="7rem" label="Loading heat map" />
                        </div>
                        <div class="snitch-scrap snitch-dash-chart-slot relative flex h-full flex-col p-3">
                            <div class="mb-1 flex items-baseline justify-between gap-2">
                                <p class="snitch-ink-label">Followers</p>
                                <p
                                    v-if="growth_delta?.week_delta != null"
                                    class="tabular-nums text-[10px] text-slate-500"
                                >
                                    {{ growth_delta.week_delta > 0 ? '+' : '' }}{{ growth_delta.week_delta }}
                                    this week
                                    <template v-if="growth_delta.week_pct != null">
                                        ({{ growth_delta.week_pct > 0 ? '+' : '' }}{{ growth_delta.week_pct }}%)
                                    </template>
                                </p>
                            </div>
                            <FollowerHistoryChart
                                v-if="follower_series"
                                class="snitch-dash-soft-in"
                                scope="corpus"
                                :points="follower_series"
                            />
                            <SnitchSkeleton v-else variant="scrap" height="8rem" label="Loading follower history" />
                        </div>
                    </div>

                    <div class="mt-3 grid items-start gap-3 lg:grid-cols-3">
                        <div class="snitch-scrap snitch-dash-chart-slot relative p-3">
                            <FormatMixChart
                                v-if="caption_intel"
                                class="snitch-dash-soft-in"
                                :formats="caption_intel.format_mix"
                            />
                            <SnitchSkeleton v-else variant="scrap" height="8rem" label="Loading format mix" />
                        </div>
                        <div class="snitch-scrap snitch-dash-chart-slot relative p-3">
                            <TimeOfDayChart
                                v-if="activity"
                                class="snitch-dash-soft-in"
                                compact
                                :hours="activity.by_time_of_day"
                            />
                            <p
                                v-if="activity"
                                class="mt-1 text-right text-[10px] tabular-nums text-slate-500"
                            >
                                {{ timeOfDayTotal }} posts · 12 wks
                            </p>
                            <SnitchSkeleton v-else variant="scrap" height="8rem" label="Loading time of day" />
                        </div>
                        <div class="snitch-scrap snitch-dash-chart-slot relative p-3">
                            <WeeklyVolumeChart
                                v-if="activity"
                                class="snitch-dash-soft-in"
                                compact
                                :weeks="activity.weekly"
                                :subtitle="`${weeklyPostTotal} posts · 12 wks`"
                            />
                            <SnitchSkeleton v-else variant="scrap" height="8rem" label="Loading weekly volume" />
                        </div>
                    </div>
                </section>

                <div class="grid gap-3 xl:grid-cols-5">
                    <DashCard
                        class="xl:col-span-2"
                        title="This week in 30 seconds"
                        why="Do-this-next lines from peer gaps."
                        formula="score = |effect| × min(1, n/20); top 5, max 1 per category; n ≥ 5."
                        anchor="insights"
                    >
                        <InsightList
                            v-if="insights"
                            :status="insights.status"
                            :reason="insights.reason"
                            :items="insights.data?.items"
                        />
                        <SnitchSkeleton v-else variant="scrap" height="6rem" label="Loading insights" />
                    </DashCard>

                    <DashCard
                        class="xl:col-span-3"
                        title="Leaderboard"
                        why="Who is ahead, and on what."
                        formula="ER = median per-follower engagement. Consistency = weeks with ≥1 post in last 8."
                        anchor="leaderboard"
                    >
                        <CompareTable
                            v-if="leaderboard"
                            :status="leaderboard.status"
                            :reason="leaderboard.reason"
                            :rows="(leaderboard.data?.rows as any) || []"
                        />
                        <SnitchSkeleton v-else variant="scrap" height="6rem" label="Loading leaderboard" />
                    </DashCard>
                </div>

                <section id="caption_intel" class="grid items-start gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="snitch-scrap p-3">
                        <p class="snitch-ink-label mb-2">Hashtags</p>
                        <div v-if="caption_intel" class="flex flex-wrap gap-1.5">
                            <span
                                v-for="row in caption_intel.hashtags"
                                :key="`hash-${row.term}`"
                                class="snitch-glance-tag"
                            >
                                #{{ row.term }}
                                <span class="tabular-nums text-slate-500">{{ row.count }}</span>
                            </span>
                            <p v-if="!caption_intel.hashtags.length" class="text-xs text-slate-500">No hashtags in this window.</p>
                        </div>
                        <SnitchSkeleton v-else variant="scrap" height="3.5rem" label="Loading hashtags" />
                    </div>
                    <div class="snitch-scrap p-3">
                        <p class="snitch-ink-label mb-2">Phrases</p>
                        <div v-if="caption_intel" class="flex flex-wrap gap-1.5">
                            <span
                                v-for="row in caption_intel.keywords"
                                :key="`kw-${row.term}`"
                                class="snitch-glance-tag"
                            >
                                {{ row.term }}
                                <span class="tabular-nums text-slate-500">{{ row.count }}</span>
                            </span>
                            <p v-if="!caption_intel.keywords.length" class="text-xs text-slate-500">No phrase chips yet.</p>
                        </div>
                        <SnitchSkeleton v-else variant="scrap" height="3.5rem" label="Loading phrases" />
                    </div>
                    <div class="snitch-scrap p-3 sm:col-span-2 xl:col-span-1">
                        <CtaLanguage
                            v-if="caption_intel"
                            :ctas="caption_intel.ctas"
                        />
                        <SnitchSkeleton v-else variant="scrap" height="3.5rem" label="Loading CTA language" />
                    </div>
                </section>

                <div
                    id="recent_posts"
                    class="grid items-start gap-3 xl:grid-cols-[minmax(0,1.45fr)_minmax(16rem,0.7fr)]"
                >
                    <div>
                        <div class="mb-2 flex items-end justify-between gap-2">
                            <div>
                                <p class="snitch-ink-label">Recent</p>
                                <h2 class="text-sm font-semibold text-slate-900">Latest posts</h2>
                            </div>
                            <Link :href="feedIndex.url()" class="text-[11px] font-medium text-slate-500 hover:text-slate-800">
                                Open feed →
                            </Link>
                        </div>
                        <div
                            v-if="recent_posts"
                            class="snitch-contact-sheet snitch-contact-sheet-proof-fill snitch-contact-sheet-dash snitch-contact-sheet-dash-clip"
                        >
                            <FeedContactCell
                                v-for="(post, index) in recent_posts"
                                :key="post.id"
                                :post="post as any"
                                :index="index"
                                :account-href="accountHrefFor(post)"
                                compact
                            />
                        </div>
                        <SnitchSkeleton v-else variant="scrap" height="14rem" label="Loading latest posts" />
                        <p
                            v-if="recent_posts && !recent_posts.length"
                            class="mt-2 text-xs text-slate-500"
                        >
                            No posts in the selected accounts yet.
                        </p>
                    </div>

                    <div id="winners">
                        <div class="mb-2 flex items-end justify-between gap-2">
                            <div>
                                <p class="snitch-ink-label">Scoreboard</p>
                                <h2 class="text-sm font-semibold text-slate-900">Top winners</h2>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded px-1.5 py-0.5 text-[10px] font-medium"
                                    :class="winnerTab === 'winners' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'"
                                    @click="winnerTab = 'winners'"
                                >
                                    Winners
                                </button>
                                <button
                                    type="button"
                                    class="rounded px-1.5 py-0.5 text-[10px] font-medium"
                                    :class="winnerTab === 'flops' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'"
                                    @click="winnerTab = 'flops'"
                                >
                                    Flops
                                </button>
                                <Link :href="winnersIndex.url()" class="text-[11px] font-medium text-slate-500 hover:text-slate-800">
                                    View all →
                                </Link>
                            </div>
                        </div>
                        <EmptyState
                            v-if="winners && (winners.status !== 'ok' || !winnerItems.length)"
                            :reason="winners.reason || 'No posts in this tab.'"
                            compact
                        />
                        <div v-else-if="winners" class="space-y-1.5">
                            <div
                                v-for="post in winnerItems"
                                :key="String(post.id)"
                                class="snitch-dash-winner-row"
                            >
                                <Link :href="feedShow.url(post.id)" class="snitch-dash-winner-thumb">
                                    <img
                                        v-if="post.thumbnail_url"
                                        :src="post.thumbnail_url"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                    >
                                </Link>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <Link
                                            v-if="post.tracked_account_id"
                                            :href="competitorShow.url(post.tracked_account_id)"
                                            class="truncate text-[11px] font-medium text-slate-800 hover:underline"
                                        >
                                            @{{ post.handle }}
                                        </Link>
                                        <span v-else class="truncate text-[11px] font-medium text-slate-800">
                                            @{{ post.handle }}
                                        </span>
                                        <span class="rounded bg-slate-900 px-1 py-0.5 text-[9px] font-semibold tabular-nums text-white">
                                            {{ post.pi.toFixed(1) }}×
                                        </span>
                                    </div>
                                    <Link
                                        :href="feedShow.url(post.id)"
                                        class="mt-0.5 line-clamp-2 text-[11px] leading-snug text-slate-600 hover:text-slate-900"
                                    >
                                        {{ post.hook || 'Open post' }}
                                    </Link>
                                </div>
                            </div>
                        </div>
                        <SnitchSkeleton v-else variant="scrap" height="14rem" label="Loading winners" />
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <DashCard
                        v-if="!panelReady || efficiency"
                        title="Efficiency map"
                        why="Volume vs quality - post more, or post better?"
                        formula="x = posts/week · y = median ER/follower · bubble = followers"
                        anchor="efficiency"
                    >
                        <EfficiencyScatter
                            v-if="efficiency"
                            :status="efficiency.status"
                            :reason="efficiency.reason"
                            :points="(efficiency.data?.points as any) || []"
                            :median-x="efficiency.data?.median_x ?? null"
                            :median-y="efficiency.data?.median_y ?? null"
                        />
                        <SnitchSkeleton v-else variant="scrap" height="8rem" label="Loading efficiency" />
                    </DashCard>

                    <DashCard
                        title="Format lift"
                        why="Which formats beat each account's usual."
                        formula="median ER(format) ÷ median ER(account); need n≥3 per format"
                        anchor="format_lift"
                    >
                        <FormatLift
                            v-if="format_lift"
                            :status="format_lift.status"
                            :reason="format_lift.reason"
                            :rows="(format_lift.data?.rows as any) || []"
                            :peer-median-lift="format_lift.data?.peer_median_lift || {}"
                        />
                        <SnitchSkeleton v-else variant="scrap" height="8rem" label="Loading format lift" />
                    </DashCard>

                    <DashCard
                        title="Share of attention"
                        why="Who gets outsized attention per post (proxy, not true SOV)."
                        formula="eng_share = Σinteractions(a)/Σall · post_share = posts(a)/posts(all)"
                        anchor="attention"
                    >
                        <AttentionBars
                            v-if="attention"
                            :status="attention.status"
                            :reason="attention.reason"
                            :rows="(attention.data?.rows as any) || []"
                        />
                        <SnitchSkeleton v-else variant="scrap" height="8rem" label="Loading attention" />
                    </DashCard>
                </div>

                <div class="grid gap-3 xl:grid-cols-2">
                    <DashCard
                        title="When posts do best"
                        why="Peer timing evidence in Europe/London, not generic '5 AM' advice."
                        formula="Cell = median Performance Index. Grey when n < 3. Your posts as dots."
                        anchor="heatmap"
                    >
                        <WhenHeatmap
                            v-if="heatmap"
                            :status="heatmap.status"
                            :reason="heatmap.reason"
                            :days="heatmap.data?.days"
                            :blocks="heatmap.data?.blocks"
                            :cells="(heatmap.data?.cells as any) || []"
                            :own-dots="heatmap.data?.own_dots || []"
                            :mode="heatmap.data?.mode || 'pi'"
                        />
                        <SnitchSkeleton v-else variant="scrap" height="8rem" label="Loading timing heatmap" />
                    </DashCard>

                    <DashCard
                        title="Your next 3 moves"
                        why="Closes the loop from analysis to action."
                        formula="Top gap rules where You is below peer median and n ≥ 5."
                        anchor="actions"
                    >
                        <ActionList
                            v-if="actions"
                            :status="actions.status"
                            :reason="actions.reason"
                            :items="actions.data?.items"
                            :peer-only="!!actions.data?.peer_only"
                        />
                        <SnitchSkeleton v-else variant="scrap" height="6rem" label="Loading actions" />
                    </DashCard>
                </div>

                <div class="grid gap-3 xl:grid-cols-3">
                    <DashCard
                        class="xl:col-span-2"
                        title="Captions and hooks"
                        why="Free changes to how posts are written."
                        formula="Length excludes trailing hashtags. CTA via regex. Hooks from top PI winners."
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
                        <SnitchSkeleton v-else variant="scrap" height="8rem" label="Loading captions" />
                    </DashCard>

                    <DashCard
                        title="Week-over-week"
                        why="What changed since you last looked."
                        formula="ISO weeks Mon-Sun in Europe/London."
                        anchor="weekly"
                    >
                        <WeeklyMultiples
                            v-if="weekly"
                            :status="weekly.status"
                            :reason="weekly.reason"
                            :weeks="(weekly.data?.weeks as any) || []"
                            :series="(weekly.data?.series as any) || []"
                            :deltas="(weekly.data?.deltas as any) || null"
                        />
                        <SnitchSkeleton v-else variant="scrap" height="8rem" label="Loading weekly" />
                    </DashCard>
                </div>

                <DashCard
                    v-if="themes"
                    title="Topics and themes"
                    why="Topic gaps peers win with that you skip."
                    formula="share = posts(theme)/posts · colour = median PI"
                    anchor="themes"
                >
                    <ThemeMatrix
                        :status="themes.status"
                        :reason="themes.reason"
                        :accounts="(themes.data?.accounts as any) || []"
                        :matrix="(themes.data?.matrix as any) || []"
                        :gaps="(themes.data?.gaps as any) || []"
                    />
                </DashCard>

                <DashCard
                    title="Data notes"
                    why="Trust after the 102% ER incident."
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
                    <SnitchSkeleton v-else variant="scrap" height="4rem" label="Loading data notes" />
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
