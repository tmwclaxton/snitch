<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { index as briefIndex } from '@/actions/App/Http/Controllers/BriefController';
import { index as competitors, show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import EmptyState from '@/components/dashboard/EmptyState.vue';
import ExecRankBars from '@/components/dashboard/ExecRankBars.vue';
import ExecStatTile from '@/components/dashboard/ExecStatTile.vue';
import ExecTakeaways from '@/components/dashboard/ExecTakeaways.vue';
import ExecWhatWorks from '@/components/dashboard/ExecWhatWorks.vue';
import ExecWinnerThumb from '@/components/dashboard/ExecWinnerThumb.vue';
import FormatMixChart from '@/components/dashboard/FormatMixChart.vue';
import PostingHeatmap from '@/components/dashboard/PostingHeatmap.vue';
import TrackedBySection from '@/components/dashboard/TrackedBySection.vue';
import VoteSection from '@/components/dashboard/VoteSection.vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import SnitchSkeleton from '@/components/SnitchSkeleton.vue';
import { activeDashboardSection, useDashboardScrollSpy } from '@/composables/useDashboardScrollSpy';
import AppLayout from '@/layouts/AppLayout.vue';
import { scrollToDashboardAnchor } from '@/lib/dashboardAnchors';
import { formatAppDate } from '@/lib/dates';
import { productPlatformLabel } from '@/lib/platforms';
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

type AdCard = {
    id: number;
    title: string;
    body: string | null;
    url: string;
    platform: string;
    last_seen_at: string | null;
};

type AdsAccount = {
    tracked_account_id: number;
    handle: string;
    count: number;
    ads: AdCard[];
};

type Gap = {
    type: 'pp' | 'x';
    value: number;
    lower?: boolean;
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
        best_times?: { label: string; score: number }[];
    } | null;
    executive?: {
        what_to_post?: { headline: string; takeaways?: Array<{ metric: string; text: string }> };
        performance?: { headline: string };
        ads?: { headline: string };
    } | null;
    trackedBy?: { count: number; since: string } | null;
    featureSuggestions?: Array<{
        id: number;
        title: string;
        body: string;
        status: string;
        votes_count: number;
        voted: boolean;
    }>;
    caption_intel?: {
        hashtags: { term: string; count: number }[];
        keywords: { term: string; count: number }[];
        ctas: { term: string; count: number; lines?: { text: string; count: number; post_id?: number | null }[] }[];
        cta_clicks: { posts_with_cta: number; posts: number };
        format_mix: { type: string; count: number }[];
    } | null;
    ads_panel?: {
        running_ads: number;
        accounts: AdsAccount[];
        recommendation: string;
    } | null;
}>();

const winnerTab = ref<'winners' | 'flops'>('winners');

const showOnboarding = computed(
    () => props.onboarding.status === 'ok' && !(props.onboarding.data?.hide ?? false),
);

const hasInstagramSet = computed(() => props.rivals.length > 0 || props.own_account != null);

const hiddenLikesCount = computed(
    () => props.controls.hidden_likes_count
        ?? props.winners?.data?.hidden_likes_count
        ?? 0,
);

const showTrackedBy = computed(
    () => props.trackedBy != null && props.trackedBy.count > 0,
);

const sectionIds = computed(() => {
    const ids = ['what-to-post', 'performance', 'ads'];

    if (showTrackedBy.value) {
        ids.push('tracked-by');
    }

    ids.push('vote');

    return ids;
});

useDashboardScrollSpy(sectionIds.value);

onMounted(() => {
    const hash = window.location.hash.replace(/^#/, '');

    if (hash && sectionIds.value.includes(hash)) {
        activeDashboardSection.value = hash;
        void scrollToDashboardAnchor(hash);
    }
});

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

function seenLabel(iso: string | null): string | null {
    return formatAppDate(iso, {
        month: 'short',
        day: 'numeric',
    });
}

function formatKpiValue(card: Record<string, unknown>): string {
    const you = card.you as number | null | undefined;
    const display = card.you_display as number | string | null | undefined;
    const unit = String(card.unit ?? '');

    if (display != null && String(card.label ?? '').startsWith('Followers')) {
        return typeof display === 'number'
            ? new Intl.NumberFormat('en-GB').format(display)
            : String(display);
    }

    if (you == null) {
        return '-';
    }

    if (unit === 'pct') {
        return `${new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 }).format(you)}%`;
    }

    return new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 }).format(you);
}

const winnerList = computed(() => {
    const data = props.winners?.data;

    if (! data) {
        return [] as WinnerPost[];
    }

    return winnerTab.value === 'flops' ? (data.flops ?? []) : (data.winners ?? []);
});

const winnerItems = computed(() => winnerList.value.slice(0, 6));

const formatLifts = computed(() => props.format_lift?.data?.peer_median_lift ?? {});

const visibleKpiCards = computed(() => {
    const cards = (props.kpis?.data?.cards ?? []) as Array<Record<string, unknown>>;

    return cards
        .filter((card) => {
            if (card.key === 'reel_reach' && card.you == null && card.you_display == null) {
                return false;
            }

            return true;
        })
        .slice(0, 4);
});

const peakHours = computed(() => {
    const hours = props.activity?.by_time_of_day ?? [];

    // Prefer daytime/evening slots for the board-slide chart - overnight
    // noise (e.g. 4am) reads as a bad recommendation.
    const daytime = hours.filter((row) => {
        const hour = typeof row.hour === 'number' ? row.hour : Number.parseInt(String(row.label), 10);

        if (Number.isFinite(hour)) {
            return hour >= 7 && hour <= 22 && row.count > 0;
        }

        return row.count > 0;
    });

    const pool = daytime.length ? daytime : hours.filter((row) => row.count > 0);

    return [...pool]
        .sort((a, b) => b.count - a.count)
        .slice(0, 3);
});

const postingTimeBars = computed(() => {
    const fromBrief = (props.weekly_brief?.best_times ?? []).map((slot) => ({
        label: slot.label,
        value: slot.score,
        suffix: `${slot.score.toFixed(1)}×`,
    }));

    if (fromBrief.length) {
        const rows = fromBrief.slice(0, 3);
        const max = Math.max(...rows.map((row) => row.value), 0.01);

        return rows.map((row) => ({
            ...row,
            width: `${Math.max(10, (row.value / max) * 100)}%`,
        }));
    }

    const rows = peakHours.value.slice(0, 3);
    const max = Math.max(...rows.map((row) => row.count), 1);

    return rows.map((row) => ({
        label: row.label,
        value: row.count,
        suffix: `${row.count} posts`,
        width: `${Math.max(10, (row.count / max) * 100)}%`,
    }));
});

const whatToPostHeadline = computed(
    () => props.executive?.what_to_post?.headline
        ?? 'Sync more posts to get a clear posting plan.',
);

const performanceHeadline = computed(
    () => props.executive?.performance?.headline
        ?? 'Add rivals to see how you compare.',
);

const adsHeadline = computed(
    () => props.executive?.ads?.headline
        ?? props.ads_panel?.recommendation
        ?? 'None of your rivals run ads.',
);

const takeaways = computed(
    () => (props.executive?.what_to_post?.takeaways ?? []).slice(0, 3),
);

const themeGaps = computed(
    () => ((props.themes?.data?.gaps as Array<{ theme: string; peer_pi: number; n: number }>) ?? []),
);

const leaderboardRows = computed(
    () => ((props.leaderboard?.data?.rows as Array<Record<string, unknown>>) ?? []) as Array<{
        handle: string;
        is_own_account?: boolean;
        er?: number | null;
        followers?: number | null;
        posts_per_week?: number | null;
    }>,
);

const detailsOpen = ref(false);
</script>

<template>
    <div class="min-h-full bg-snitch-paper px-2 py-2 sm:px-3">
        <Head title="Dashboard" />

        <div class="mx-auto max-w-none space-y-8">
            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-2 border-b border-snitch-ink/10 pb-2">
                <h1 class="hidden shrink-0 text-sm font-semibold tracking-tight text-snitch-ink sm:block">
                    Dashboard
                </h1>

                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1">
                    <div
                        v-if="own_account"
                        class="inline-flex h-7 items-center gap-1 rounded-full border border-snitch-ink bg-snitch-ink px-2 text-sm font-medium text-white"
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
                        class="inline-flex h-7 items-center gap-1 rounded-full border px-2 text-sm font-medium"
                        :class="
                            selected.includes(rival.handle.toLowerCase())
                                ? 'border-snitch-ink bg-snitch-ink text-white'
                                : rival.no_posts_in_period
                                    ? 'border-snitch-ink/15 bg-white text-snitch-ink/40'
                                    : 'border-snitch-ink/15 bg-white text-snitch-ink'
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

                <div class="ml-auto flex shrink-0 flex-wrap items-center gap-2">
                    <label class="group relative inline-flex h-7 cursor-pointer items-center gap-1.5 text-sm text-snitch-ink/70">
                        <input
                            type="checkbox"
                            class="peer sr-only"
                            :checked="!!show_hidden_likes"
                            aria-label="Show hidden-likes posts"
                            @change="refreshQuery({ hidden: !show_hidden_likes })"
                        >
                        <span
                            class="relative inline-flex h-3.5 w-6 shrink-0 items-center rounded-full bg-snitch-ink/15 transition-colors peer-checked:bg-snitch-ink peer-focus-visible:ring-2 peer-focus-visible:ring-snitch-ink/30"
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
                            class="tabular-nums text-snitch-ink/45"
                        >
                            {{ hiddenLikesCount }}
                        </span>
                        <span
                            class="relative inline-flex text-snitch-ink/40 group-hover:text-snitch-ink"
                            tabindex="0"
                            aria-label="About hidden-likes posts"
                        >
                            <Info class="h-3.5 w-3.5" />
                            <span
                                role="tooltip"
                                class="pointer-events-none absolute right-0 top-full z-20 mt-1.5 w-60 rounded border border-snitch-ink/10 bg-white p-2 text-left text-sm leading-snug text-snitch-ink/70 opacity-0 shadow-sm transition-opacity group-hover:opacity-100 group-focus-within:opacity-100"
                            >
                                Some accounts hide like counts. Turn this on to include those posts, ranked on comments and views.
                            </span>
                        </span>
                    </label>
                    <div class="flex items-center gap-0.5 rounded border border-snitch-ink/15 p-0.5">
                        <button
                            v-for="days in periods"
                            :key="days"
                            type="button"
                            class="rounded px-2 py-1 text-sm font-medium"
                            :class="period === days ? 'bg-snitch-ink text-white' : 'text-snitch-ink/70 hover:bg-snitch-ink/5'"
                            @click="refreshQuery({ period: days })"
                        >
                            {{ days }}d
                        </button>
                    </div>
                </div>
            </div>

            <section
                v-if="showOnboarding"
                id="onboarding"
                class="rounded border border-snitch-ink/10 bg-white p-4"
            >
                <h2 class="font-display text-lg font-semibold text-snitch-ink">
                    Get ready
                </h2>
                <ol class="mt-3 grid gap-2 sm:grid-cols-3">
                    <li
                        v-for="(step, index) in onboarding.data?.steps || []"
                        :key="step.key"
                        class="flex gap-2 rounded border border-snitch-ink/10 px-3 py-2 text-sm"
                    >
                        <span
                            class="flex size-5 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                            :class="step.done ? 'bg-snitch-spot text-snitch-ink' : 'bg-snitch-ink/10 text-snitch-ink/70'"
                        >
                            {{ step.done ? '✓' : index + 1 }}
                        </span>
                        <span class="text-snitch-ink">{{ step.label }}</span>
                    </li>
                </ol>
                <Link
                    v-if="rivals.length === 0"
                    :href="competitors()"
                    class="mt-3 inline-flex rounded bg-snitch-ink px-3 py-1.5 text-sm font-medium text-white"
                >
                    Go to Tracking
                </Link>
            </section>

            <template v-if="hasInstagramSet">
                <!-- 1. What should we post? -->
                <section
                    id="what-to-post"
                    class="scroll-mt-14 space-y-4 border-t border-snitch-ink/10 pt-6"
                >
                    <header class="space-y-2">
                        <p class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                            What should we post?
                        </p>
                        <h2 class="font-display max-w-4xl text-2xl font-semibold tracking-tight text-snitch-ink md:text-3xl">
                            {{ whatToPostHeadline }}
                        </h2>
                    </header>

                    <div
                        v-if="weekly_brief?.ideas?.length"
                        class="space-y-2"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-sm font-semibold text-snitch-ink">Post this next</h3>
                            <Link
                                :href="briefIndex.url()"
                                class="text-sm font-medium text-snitch-ink/60 underline-offset-2 hover:text-snitch-ink hover:underline"
                            >
                                See full brief
                            </Link>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <Link
                                v-for="(idea, index) in weekly_brief.ideas.slice(0, 3)"
                                :key="`${idea.slot}-${index}`"
                                :href="briefIndex.url()"
                                class="flex min-w-0 flex-col gap-2 rounded border border-snitch-ink/10 bg-white p-3 hover:border-snitch-ink/30"
                            >
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <span class="rounded bg-snitch-ink px-1.5 py-0.5 font-medium uppercase tracking-wide text-white">
                                        {{ idea.format }}
                                    </span>
                                    <span class="whitespace-nowrap tabular-nums text-snitch-ink/55">{{ idea.slot }}</span>
                                </div>
                                <span class="text-sm leading-snug text-snitch-ink">{{ idea.hook }}</span>
                            </Link>
                        </div>
                    </div>

                    <div
                        v-if="postingTimeBars.length"
                        class="rounded border border-snitch-ink/10 bg-white p-3"
                    >
                        <p class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                            Best posting times
                        </p>
                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="slot in postingTimeBars"
                                :key="slot.label"
                                class="grid grid-cols-[7rem_1fr_auto] items-center gap-2"
                            >
                                <span class="whitespace-nowrap text-sm font-medium text-snitch-ink">{{ slot.label }}</span>
                                <div class="h-3 overflow-hidden rounded-sm bg-snitch-ink/8">
                                    <div
                                        class="h-full rounded-sm bg-snitch-ink"
                                        :style="{ width: slot.width }"
                                    />
                                </div>
                                <span class="font-mono text-sm tabular-nums text-snitch-ink/70">{{ slot.suffix }}</span>
                            </li>
                        </ul>
                    </div>

                    <div id="insights">
                        <ExecTakeaways
                            v-if="takeaways.length"
                            :items="takeaways"
                        />
                        <SnitchSkeleton
                            v-else-if="!insights"
                            variant="scrap"
                            height="4rem"
                            label="Loading takeaways"
                        />
                    </div>

                    <div id="caption_intel">
                        <ExecWhatWorks
                            v-if="caption_intel || themes"
                            :hashtags="caption_intel?.hashtags"
                            :ctas="caption_intel?.ctas"
                            :gaps="themeGaps"
                        />
                        <SnitchSkeleton
                            v-else
                            variant="scrap"
                            height="4rem"
                            label="Loading what works"
                        />
                    </div>
                </section>

                <!-- 2. How are they performing? -->
                <section
                    id="performance"
                    class="scroll-mt-14 space-y-4 border-t border-snitch-ink/10 pt-6"
                >
                    <header class="space-y-2">
                        <p class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                            How are they performing?
                        </p>
                        <h2 class="font-display max-w-4xl text-2xl font-semibold tracking-tight text-snitch-ink md:text-3xl">
                            {{ performanceHeadline }}
                        </h2>
                    </header>

                    <div
                        id="rail"
                        class="grid gap-2"
                        :class="{
                            'sm:grid-cols-2 xl:grid-cols-4': visibleKpiCards.length >= 4,
                            'sm:grid-cols-3': visibleKpiCards.length === 3,
                            'sm:grid-cols-2': visibleKpiCards.length === 2,
                            'grid-cols-1': visibleKpiCards.length <= 1,
                        }"
                    >
                        <template v-if="kpis?.status === 'ok' && visibleKpiCards.length">
                            <ExecStatTile
                                v-for="card in visibleKpiCards"
                                :key="String(card.key)"
                                :label="String(card.label)"
                                :value="formatKpiValue(card)"
                                :gap="(card.gap as Gap | null) ?? null"
                            />
                        </template>
                        <template v-else>
                            <SnitchSkeleton
                                v-for="slot in 3"
                                :key="`kpi-${slot}`"
                                variant="scrap"
                                height="5.5rem"
                                :label="`Loading KPI ${slot}`"
                            />
                        </template>
                    </div>

                    <div class="grid items-stretch gap-4 lg:grid-cols-2">
                        <div
                            id="leaderboard"
                            class="flex h-full min-h-[12rem] flex-col rounded border border-snitch-ink/10 bg-white p-3"
                        >
                            <ExecRankBars
                                v-if="leaderboard"
                                class="flex-1"
                                :rows="leaderboardRows"
                            />
                            <SnitchSkeleton
                                v-else
                                variant="scrap"
                                height="8rem"
                                label="Loading leaderboard"
                            />
                        </div>
                        <div
                            id="format_mix"
                            class="flex h-full min-h-[12rem] flex-col rounded border border-snitch-ink/10 bg-white p-3"
                        >
                            <FormatMixChart
                                v-if="caption_intel"
                                class="flex-1"
                                :formats="caption_intel.format_mix"
                                :lifts="formatLifts"
                            />
                            <SnitchSkeleton
                                v-else
                                variant="scrap"
                                height="8rem"
                                label="Loading format mix"
                            />
                        </div>
                    </div>

                    <section
                        id="activity"
                        class="rounded border border-snitch-ink/10 bg-white p-3"
                    >
                        <div class="mb-2 flex items-baseline justify-between gap-2">
                            <p class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                                Posting heat map
                            </p>
                            <p class="text-sm tabular-nums text-snitch-ink/45">16 wks</p>
                        </div>
                        <PostingHeatmap
                            v-if="activity"
                            class="snitch-heatmap--dash"
                            :days="activity.heatmap"
                        />
                        <SnitchSkeleton
                            v-else
                            variant="scrap"
                            height="5rem"
                            label="Loading heat map"
                        />
                    </section>

                    <section
                        id="winners"
                        class="space-y-2"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-snitch-ink">Winning posts</h3>
                            <button
                                type="button"
                                class="rounded px-2 py-0.5 text-sm font-medium"
                                :class="winnerTab === 'winners' ? 'bg-snitch-ink text-white' : 'bg-snitch-ink/5 text-snitch-ink/70'"
                                @click="winnerTab = 'winners'"
                            >
                                Winners
                            </button>
                            <button
                                type="button"
                                class="rounded px-2 py-0.5 text-sm font-medium"
                                :class="winnerTab === 'flops' ? 'bg-snitch-ink text-white' : 'bg-snitch-ink/5 text-snitch-ink/70'"
                                @click="winnerTab = 'flops'"
                            >
                                Flops
                            </button>
                        </div>
                        <EmptyState
                            v-if="winners && (winners.status !== 'ok' || !winnerItems.length)"
                            :reason="winners.reason || 'No posts in this tab.'"
                            compact
                        />
                        <div
                            v-else-if="winnerItems.length"
                            class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6"
                        >
                            <ExecWinnerThumb
                                v-for="post in winnerItems"
                                :key="String(post.id)"
                                :post="post"
                            />
                        </div>
                        <SnitchSkeleton
                            v-else-if="!winners"
                            variant="scrap"
                            height="8rem"
                            label="Loading winners"
                        />
                    </section>
                </section>

                <!-- 3. Are they running ads? -->
                <section
                    id="ads"
                    class="scroll-mt-14 space-y-4 border-t border-snitch-ink/10 pt-6"
                >
                    <header class="space-y-2">
                        <p class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                            Are they running ads?
                        </p>
                        <h2 class="font-display max-w-4xl text-2xl font-semibold tracking-tight text-snitch-ink md:text-3xl">
                            {{ adsHeadline }}
                        </h2>
                    </header>

                    <div
                        v-if="!ads_panel"
                        class="grid gap-2 sm:grid-cols-2"
                        aria-busy="true"
                    >
                        <SnitchSkeleton
                            v-for="row in 2"
                            :key="`ad-skel-${row}`"
                            variant="scrap"
                            height="4rem"
                            :label="`Loading ads ${row}`"
                        />
                    </div>
                    <div
                        v-else-if="ads_panel.accounts.length"
                        class="space-y-3"
                    >
                        <details @toggle="detailsOpen = ($event.target as HTMLDetailsElement).open">
                            <summary class="cursor-pointer text-sm font-medium text-snitch-ink/70 hover:text-snitch-ink">
                                {{ detailsOpen ? 'Hide ad creatives' : 'See ad creatives' }}
                            </summary>
                            <div class="mt-3 space-y-3">
                                <div
                                    v-for="account in ads_panel.accounts"
                                    :key="account.handle"
                                    class="rounded border border-snitch-ink/10 bg-white p-3"
                                >
                                    <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                                        <Link
                                            v-if="account.tracked_account_id"
                                            :href="competitorShow.url(account.tracked_account_id)"
                                            class="whitespace-nowrap text-sm font-semibold text-snitch-ink hover:underline"
                                        >
                                            @{{ account.handle }}
                                        </Link>
                                        <span class="text-sm tabular-nums text-snitch-ink/55">
                                            {{ account.count }} active
                                        </span>
                                    </div>
                                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        <a
                                            v-for="ad in account.ads"
                                            :key="ad.id"
                                            :href="ad.url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="rounded border border-snitch-ink/10 px-2.5 py-2 hover:border-snitch-ink/30"
                                        >
                                            <p class="text-sm text-snitch-ink/55">
                                                {{ productPlatformLabel(ad.platform) }}
                                                <span
                                                    v-if="seenLabel(ad.last_seen_at)"
                                                    class="ms-1 tabular-nums"
                                                >
                                                    · {{ seenLabel(ad.last_seen_at) }}
                                                </span>
                                            </p>
                                            <p class="mt-1 text-sm font-medium leading-snug text-snitch-ink">
                                                {{ ad.title }}
                                            </p>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </div>
                </section>
            </template>

            <div
                v-else-if="!showOnboarding"
                class="rounded border border-dashed border-snitch-ink/20 bg-white p-8 text-center"
            >
                <h2 class="font-display text-xl font-semibold text-snitch-ink">
                    {{ own_account ? 'No rivals to compare yet' : 'No Instagram competitors yet' }}
                </h2>
                <p class="mt-2 text-sm text-snitch-ink/60">
                    Add Instagram competitor handles on Tracking to populate this dashboard.
                </p>
                <Link
                    :href="competitors()"
                    class="mt-4 inline-block rounded bg-snitch-ink px-3 py-1.5 text-sm text-white"
                >
                    Go to Tracking
                </Link>
            </div>

            <section
                v-if="showTrackedBy"
                id="tracked-by"
                class="scroll-mt-14 space-y-3 border-t border-snitch-ink/10 pt-6"
            >
                <header>
                    <h2 class="font-display text-2xl font-semibold tracking-tight text-snitch-ink md:text-3xl">
                        Is anyone <span class="snitch-highlight">tracking you</span>?
                    </h2>
                </header>
                <TrackedBySection :tracked-by="trackedBy ?? null" />
            </section>

            <section
                id="vote"
                class="scroll-mt-14 space-y-3 border-t border-snitch-ink/10 pt-6"
            >
                <header>
                    <h2 class="font-display text-2xl font-semibold tracking-tight text-snitch-ink md:text-3xl">
                        Cast your <span class="snitch-highlight">vote</span>
                    </h2>
                </header>
                <VoteSection :suggestions="featureSuggestions ?? []" />
            </section>
        </div>
    </div>
</template>
