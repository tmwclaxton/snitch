<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Clapperboard,
    Trophy,
    Users,
} from '@lucide/vue';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import type { Component } from 'vue';
import { index as adsIndex } from '@/actions/App/Http/Controllers/AdsController';
import { index as competitors, show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import DashboardController from '@/actions/App/Http/Controllers/DashboardController';
import { index as feed, show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import { index as winners } from '@/actions/App/Http/Controllers/WinnerController';
import CtaLanguage from '@/components/CtaLanguage.vue';
import FollowerHistoryChart from '@/components/dashboard/FollowerHistoryChart.vue';
import type { FollowerPoint } from '@/components/dashboard/FollowerHistoryChart.vue';
import FormatMixChart from '@/components/dashboard/FormatMixChart.vue';
import PlatformSplitChart from '@/components/dashboard/PlatformSplitChart.vue';
import PostingHeatmap from '@/components/dashboard/PostingHeatmap.vue';
import TimeOfDayChart from '@/components/dashboard/TimeOfDayChart.vue';
import WeeklyVolumeChart from '@/components/dashboard/WeeklyVolumeChart.vue';
import FeedContactCell from '@/components/FeedContactCell.vue';
import PlatformEmbed from '@/components/PlatformEmbed.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import SnitchFacePile from '@/components/SnitchFacePile.vue';
import type { SnitchFace } from '@/components/SnitchFacePile.vue';
import SnitchSkeleton from '@/components/SnitchSkeleton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatFollowers, winnerStatPills } from '@/lib/metrics';
import type { PostMetrics } from '@/lib/metrics';
import { platformLabel } from '@/lib/platforms';

type RecentPost = {
    id: number;
    platform: string;
    type: string;
    url: string | null;
    media_url: string | null;
    cover_url?: string | null;
    media_availability?: string | null;
    metrics?: PostMetrics | null;
    embed?: EmbedConfig | null;
    tracked_account?: { id?: number; handle: string; display_name?: string | null } | null;
    analysis?: {
        status: string;
        hook: string | null;
        concept?: string | null;
        topics?: string[] | null;
    } | null;
    winner_insight?: { score: number } | null;
};

type ActivityPayload = {
    heatmap: Array<{ date: string; count: number }>;
    weekly: Array<{ week_start: string; label: string; count: number }>;
    by_platform: Array<{ platform: string; count: number }>;
    by_time_of_day: Array<{
        hour: number;
        label: string;
        count: number;
    }>;
};

type InsightsPayload = {
    engagement: {
        posts: number;
        avg_views: number;
        avg_likes: number;
        avg_comments: number;
        avg_shares: number;
        avg_rate: number;
    };
    format_mix: Array<{ type: string; count: number }>;
    hashtags: Array<{ term: string; count: number }>;
    keywords: Array<{ term: string; count: number }>;
    ctas: Array<{ term: string; count: number; lines?: Array<{ text: string; count: number; post_id?: number | null }> }>;
    cta_clicks: {
        posts_with_cta: number;
        posts: number;
    };
    growth: {
        followers: number;
        week_delta: number | null;
        week_pct: number | null;
        month_delta: number | null;
        month_pct: number | null;
        since_first_delta?: number | null;
        since_first_pct?: number | null;
    };
    follower_series?: FollowerPoint[];
    paid_vs_organic?: {
        organic: number;
        sponsored: number;
        running_ads: number;
    };
    ads: Array<{
        id: number;
        title: string;
        body: string | null;
        url: string;
        platform: string;
    }>;
    playbook: {
        peak_hour_label: string | null;
        top_format: string | null;
        top_hashtag: string | null;
    };
};

type TopWinner = {
    id: number;
    score: number;
    why: string;
    post: {
        id: number;
        platform: string;
        url: string | null;
        media_url: string | null;
        cover_url?: string | null;
        metrics?: PostMetrics | null;
        embed?: EmbedConfig | null;
        tracked_account?: { handle: string } | null;
        analysis?: { hook: string | null; concept?: string | null } | null;
    };
};

const props = defineProps<{
    stats: {
        tracked_accounts: number;
        posts: number;
        winners: number;
        analysis_backlog: number;
        analysis_failed: number;
        last_synced_at: string | null;
        followers?: number;
    };
    activity?: ActivityPayload | null;
    insights?: InsightsPayload | null;
    recent_posts?: RecentPost[] | null;
    frames: number;
    top_winners?: TopWinner[] | null;
    snitches: SnitchFace[];
}>();

defineOptions({
    layout: AppLayout,
});

function formatDelta(value: number): string {
    if (value > 0) {
        return `+${Number.isInteger(value) ? String(value) : value.toFixed(1)}`;
    }

    return String(value);
}

const lastSyncLabel = computed(() => {
    if (!props.stats.last_synced_at) {
        return 'Never synced';
    }

    return `Last sync ${new Date(props.stats.last_synced_at).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    })}`;
});

const playbookLine = computed(() => {
    const book = props.insights?.playbook;

    if (!book) {
        return null;
    }

    const parts: string[] = [];

    if (book.peak_hour_label) {
        parts.push(`They post most around ${book.peak_hour_label}.`);
    }

    if (book.top_format) {
        parts.push(`Format lean is ${book.top_format}.`);
    }

    if (book.top_hashtag) {
        parts.push(`Top tag #${book.top_hashtag}.`);
    }

    return parts.length ? parts.join(' ') : null;
});

const statCards = computed(() => [
    {
        label: 'Accounts',
        value: props.stats.tracked_accounts,
        href: competitors.url(),
        hint: `${formatFollowers(props.stats.followers ?? 0)} followers · ${lastSyncLabel.value}`,
        icon: Users as Component,
    },
    {
        label: 'Posts',
        value: props.stats.posts,
        href: feed.url(),
        hint: 'Reels, stills, and carousels',
        icon: Clapperboard as Component,
    },
    {
        label: 'Winners',
        value: props.stats.winners,
        href: winners.url(),
        hint: 'Cleared your rules',
        icon: Trophy as Component,
    },
]);

function accountHref(post: RecentPost): string | null {
    const id = post.tracked_account?.id;

    return id != null ? competitorShow.url(id) : null;
}

const framesSheetRef = ref<HTMLElement | null>(null);
const FRAME_ROWS = 2;
const FRAME_LIMIT = 24;
let frameTimer: ReturnType<typeof setTimeout> | null = null;
let requestedFrames: number | null = null;
let framePasses = 0;

function frameColumns(sheet: HTMLElement): number {
    const tracks = getComputedStyle(sheet).gridTemplateColumns;

    if (tracks === '' || tracks === 'none') {
        return 1;
    }

    return Math.max(1, tracks.split(' ').filter((track) => track.trim() !== '').length);
}

function fittedFrames(sheet: HTMLElement): number {
    const columns = frameColumns(sheet);

    return Math.min(FRAME_LIMIT, Math.max(columns, columns * FRAME_ROWS));
}

function applyFittedFrames(): void {
    const sheet = framesSheetRef.value;

    if (sheet == null) {
        return;
    }

    const fitted = fittedFrames(sheet);

    if (fitted === props.frames || fitted === requestedFrames || framePasses >= 2) {
        if (fitted === props.frames) {
            requestedFrames = null;
            framePasses = 0;
        }

        return;
    }

    requestedFrames = fitted;
    framePasses += 1;

    router.get(
        DashboardController.url(),
        { frames: fitted },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['recent_posts', 'frames'],
            onFinish: () => {
                if (props.frames === requestedFrames) {
                    requestedFrames = null;
                }

                scheduleFrames();
            },
        },
    );
}

function scheduleFrames(): void {
    if (frameTimer != null) {
        clearTimeout(frameTimer);
    }

    frameTimer = setTimeout(() => {
        frameTimer = null;
        applyFittedFrames();
    }, 150);
}

watch(framesSheetRef, (sheet, _previous, onCleanup) => {
    scheduleFrames();

    if (sheet == null) {
        return;
    }

    const observer = new ResizeObserver(() => {
        scheduleFrames();
    });
    observer.observe(sheet);
    onCleanup(() => observer.disconnect());
});

watch(
    () => props.recent_posts?.length,
    () => {
        void nextTick(() => {
            scheduleFrames();
        });
    },
);

onMounted(() => {
    scheduleFrames();
    window.addEventListener('resize', onFramesResize);
});

onUnmounted(() => {
    if (frameTimer != null) {
        clearTimeout(frameTimer);
    }

    window.removeEventListener('resize', onFramesResize);
});

function onFramesResize(): void {
    framePasses = 0;
    scheduleFrames();
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-2 py-6 sm:px-3 sm:py-8">
        <Head title="Dashboard" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="snitch-app-canvas">
            <header class="flex flex-wrap items-end justify-between gap-4 border-b border-snitch-ink/10 pb-4">
                <div class="min-w-0">
                    <h1 class="snitch-display relative text-3xl text-snitch-ink sm:text-4xl">
                        <span
                            class="pointer-events-none absolute inset-0 translate-x-[2px] translate-y-[1px] text-snitch-spot opacity-55 mix-blend-multiply select-none dark:mix-blend-plus-lighter dark:opacity-70"
                            aria-hidden="true"
                        >Competitor Analytics</span>
                        <span class="relative">Competitor Analytics</span>
                    </h1>
                    <p class="mt-1.5 max-w-xl text-sm text-snitch-ink/65">
                        {{ playbookLine ?? 'Cadence, mix, captions, and winners - what rivals posted and what to remake.' }}
                    </p>
                </div>
            </header>

            <div class="snitch-dash-rail mt-4">
                <Link
                    v-for="card in statCards"
                    :key="card.label"
                    :href="card.href"
                    class="snitch-dash-rail-item"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span class="snitch-ink-label">{{ card.label }}</span>
                        <component
                            :is="card.icon"
                            class="size-3.5 shrink-0 opacity-60"
                            aria-hidden="true"
                        />
                    </span>
                    <span class="snitch-display text-2xl tabular-nums">{{ card.value }}</span>
                    <span class="text-[11px] leading-snug text-snitch-ink/55">{{ card.hint }}</span>
                </Link>
                <div
                    v-if="insights"
                    class="snitch-dash-rail-item"
                >
                    <span class="snitch-ink-label">Avg views</span>
                    <span class="snitch-display text-2xl tabular-nums">{{ formatFollowers(insights.engagement.avg_views) }}</span>
                </div>
                <div
                    v-if="insights"
                    class="snitch-dash-rail-item"
                >
                    <span class="snitch-ink-label">Avg likes</span>
                    <span class="snitch-display text-2xl tabular-nums">{{ formatFollowers(insights.engagement.avg_likes) }}</span>
                </div>
                <div
                    v-if="insights"
                    class="snitch-dash-rail-item"
                >
                    <span class="snitch-ink-label">Engagement rate</span>
                    <span class="snitch-display text-2xl tabular-nums">{{ insights.engagement.avg_rate.toFixed(2) }}%</span>
                </div>
                <div
                    v-if="insights"
                    class="snitch-dash-rail-item"
                >
                    <span class="snitch-ink-label">Growth</span>
                    <span class="snitch-display text-2xl tabular-nums">{{ formatFollowers(insights.growth.followers) }}</span>
                    <span
                        v-if="insights.growth.week_delta != null"
                        class="text-[11px] leading-snug text-snitch-ink/55"
                    >
                        <span class="tabular-nums">{{ formatDelta(insights.growth.week_delta) }}</span>
                        this week
                        <span
                            v-if="insights.growth.week_pct != null"
                            class="tabular-nums"
                        >
                            ({{ formatDelta(insights.growth.week_pct) }}%)
                        </span>
                    </span>
                    <span
                        v-else-if="insights.growth.since_first_delta != null"
                        class="text-[11px] leading-snug text-snitch-ink/55"
                    >
                        <span class="tabular-nums">{{ formatDelta(insights.growth.since_first_delta) }}</span>
                        since first reading
                    </span>
                    <span
                        v-else
                        class="text-[11px] leading-snug text-snitch-ink/55"
                    >No earlier count yet</span>
                </div>
                <div
                    v-if="insights?.paid_vs_organic"
                    class="snitch-dash-rail-item"
                >
                    <span class="snitch-ink-label">Paid vs organic</span>
                    <span class="snitch-display text-2xl tabular-nums">
                        {{ insights.paid_vs_organic.sponsored }}
                        <span class="text-base text-snitch-ink/45">/</span>
                        {{ insights.paid_vs_organic.organic }}
                    </span>
                    <span class="text-[11px] leading-snug text-snitch-ink/55">
                        Sponsored / organic
                        <template v-if="insights.paid_vs_organic.running_ads > 0">
                            · {{ insights.paid_vs_organic.running_ads }} library ads
                        </template>
                    </span>
                </div>
                <div
                    v-if="insights"
                    class="snitch-dash-rail-item"
                >
                    <span class="snitch-ink-label">Posts with an ask</span>
                    <span class="snitch-display text-2xl tabular-nums">{{ insights.cta_clicks.posts_with_cta }}</span>
                    <span class="text-[11px] leading-snug text-snitch-ink/55">In the caption</span>
                </div>
            </div>

            <section class="mt-3">
                <div class="grid items-stretch gap-3 lg:grid-cols-[minmax(0,1.45fr)_minmax(16rem,0.8fr)]">
                    <div class="snitch-scrap relative flex h-full flex-col p-3 pt-4">
                        <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                        <p class="snitch-ink-label mb-2">Heat map</p>
                        <PostingHeatmap
                            v-if="activity"
                            :days="activity.heatmap"
                        />
                        <SnitchSkeleton
                            v-else
                            variant="scrap"
                            height="7rem"
                            label="Loading heat map"
                        />
                    </div>
                    <div class="snitch-scrap relative flex h-full flex-col p-3 pt-4">
                        <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
                        <FollowerHistoryChart
                            v-if="insights"
                            scope="corpus"
                            :points="insights.follower_series ?? []"
                        />
                        <SnitchSkeleton
                            v-else
                            variant="scrap"
                            height="8rem"
                            label="Loading follower history"
                        />
                    </div>
                </div>
                <div class="mt-3 grid items-start gap-3 lg:grid-cols-4">
                    <div class="snitch-scrap relative p-3 pt-4">
                        <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
                        <PlatformSplitChart
                            v-if="activity"
                            :platforms="activity.by_platform"
                        />
                        <SnitchSkeleton
                            v-else
                            variant="scrap"
                            height="8rem"
                            label="Loading platform split chart"
                        />
                    </div>
                    <div class="snitch-scrap relative p-3 pt-4">
                        <span class="snitch-tape right-6 -top-2" aria-hidden="true" />
                        <FormatMixChart
                            v-if="insights"
                            :formats="insights.format_mix"
                        />
                        <SnitchSkeleton
                            v-else
                            variant="scrap"
                            height="8rem"
                            label="Loading format mix chart"
                        />
                    </div>
                    <div class="snitch-scrap relative p-3 pt-4">
                        <span class="snitch-tape right-4 -top-2" aria-hidden="true" />
                        <TimeOfDayChart
                            v-if="activity"
                            compact
                            :hours="activity.by_time_of_day"
                        />
                        <SnitchSkeleton
                            v-else
                            variant="scrap"
                            height="8rem"
                            label="Loading time of day chart"
                        />
                    </div>
                    <div class="snitch-scrap relative p-3 pt-4">
                        <span class="snitch-tape left-4 -top-2" aria-hidden="true" />
                        <WeeklyVolumeChart
                            v-if="activity"
                            compact
                            :weeks="activity.weekly"
                        />
                        <SnitchSkeleton
                            v-else
                            variant="scrap"
                            height="8rem"
                            label="Loading weekly volume chart"
                        />
                    </div>
                </div>
            </section>

            <section class="mt-3">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:gap-x-4">
                            <h2 class="snitch-display shrink-0 text-2xl text-snitch-ink">
                                Your tracked accounts
                            </h2>
                            <div class="min-w-0 max-w-full">
                                <SnitchFacePile :snitches="snitches" />
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="insights == null"
                    class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                    aria-live="polite"
                    aria-label="Loading mix and captions"
                >
                    <SnitchSkeleton
                        v-for="row in 3"
                        :key="`insight-skel-${row}`"
                        variant="scrap"
                        height="6rem"
                    />
                </div>
                <div
                    v-else
                    class="mt-2 space-y-2"
                >
                    <div class="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="min-w-0">
                        <p class="snitch-ink-label mb-2">Hashtags</p>
                        <div
                            v-if="insights.hashtags.length"
                            class="flex flex-wrap gap-1.5"
                        >
                            <span
                                v-for="row in insights.hashtags"
                                :key="`hash-${row.term}`"
                                class="snitch-glance-tag"
                            >
                                #{{ row.term }}
                                <span class="tabular-nums text-snitch-ink/50">{{ row.count }}</span>
                            </span>
                        </div>
                        <p
                            v-else
                            class="text-sm text-snitch-ink/60"
                        >
                            No hashtags in recent captions.
                        </p>
                    </div>
                    <div class="min-w-0">
                        <p class="snitch-ink-label mb-2">Keywords</p>
                        <div
                            v-if="insights.keywords.length"
                            class="flex flex-wrap gap-1.5"
                        >
                            <span
                                v-for="row in insights.keywords"
                                :key="`key-${row.term}`"
                                class="snitch-glance-tag"
                            >
                                {{ row.term }}
                                <span class="tabular-nums text-snitch-ink/50">{{ row.count }}</span>
                            </span>
                        </div>
                        <p
                            v-else
                            class="text-sm text-snitch-ink/60"
                        >
                            No caption keywords yet.
                        </p>
                    </div>
                    <div class="min-w-0">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <p class="snitch-ink-label">Active ads</p>
                            <Link
                                :href="adsIndex.url()"
                                class="text-xs font-medium text-snitch-ink/55 underline decoration-snitch-ink/20 underline-offset-4 transition hover:text-snitch-ink"
                            >
                                View all
                            </Link>
                        </div>
                        <p
                            v-if="(insights.paid_vs_organic?.running_ads ?? 0) > 0"
                            class="mb-1.5 text-sm tabular-nums text-snitch-ink/70"
                        >
                            {{ insights.paid_vs_organic?.running_ads }} running
                        </p>
                        <div
                            v-if="insights.ads.length"
                            class="space-y-1.5"
                        >
                            <a
                                v-for="ad in insights.ads"
                                :key="ad.id"
                                :href="ad.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="block truncate text-sm text-snitch-ink underline decoration-snitch-ink/20 underline-offset-4"
                            >
                                {{ ad.title }}
                            </a>
                        </div>
                        <p
                            v-else
                            class="text-sm text-snitch-ink/60"
                        >
                            No library ads yet.
                        </p>
                    </div>
                    </div>
                    <CtaLanguage
                        class="snitch-dash-cta"
                        :ctas="insights.ctas"
                    />
                </div>
            </section>

            <div class="mt-3 grid items-start gap-3 xl:grid-cols-[minmax(0,1.45fr)_minmax(16rem,0.7fr)]">
                <section>
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="snitch-ink-label">Recent</p>
                            <h2 class="snitch-display mt-1 text-2xl text-snitch-ink">
                                Latest posts
                            </h2>
                        </div>
                        <Link
                            :href="feed.url()"
                            class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                        >
                            <Clapperboard class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                            <span class="relative z-10">Open feed</span>
                        </Link>
                    </div>

                    <div
                        v-if="recent_posts === undefined || recent_posts === null"
                        ref="framesSheetRef"
                        class="snitch-contact-sheet snitch-contact-sheet-proof snitch-contact-sheet-proof-fill snitch-contact-sheet-dash mt-3 grid"
                        aria-live="polite"
                    >
                        <div
                            v-for="index in frames"
                            :key="`recent-skel-${index}`"
                            class="p-2"
                        >
                            <SnitchSkeleton
                                variant="polaroid"
                                width="100%"
                                :label="`Loading recent frame ${index}`"
                            />
                        </div>
                    </div>
                    <div
                        v-else-if="recent_posts.length"
                        ref="framesSheetRef"
                        class="snitch-contact-sheet snitch-contact-sheet-proof snitch-contact-sheet-proof-fill snitch-contact-sheet-dash snitch-contact-reveal mt-3 grid"
                    >
                        <FeedContactCell
                            v-for="(post, index) in recent_posts"
                            :key="post.id"
                            :post="post"
                            :index="index"
                            :account-href="accountHref(post)"
                            compact
                        />
                    </div>
                    <div
                        v-else
                        class="snitch-scrap relative mt-5 p-6"
                    >
                        <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
                        <Users class="size-8 text-snitch-ink/35" aria-hidden="true" />
                        <p class="snitch-display mt-3 text-xl">No frames yet</p>
                        <p class="mt-2 text-sm text-snitch-ink/65">
                            Add accounts and sync to fill the board.
                        </p>
                        <Link
                            :href="competitors.url()"
                            class="snitch-btn snitch-btn-spot mt-4"
                        >
                            <span class="relative z-10 inline-flex items-center gap-2">
                                <Users class="size-3.5 shrink-0" aria-hidden="true" />
                                Tracking
                            </span>
                        </Link>
                    </div>
                </section>

                <section>
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="snitch-ink-label">Scoreboard</p>
                            <h2 class="snitch-display mt-1 text-2xl text-snitch-ink">
                                Top winners
                            </h2>
                        </div>
                        <Link
                            :href="winners.url()"
                            class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                        >
                            <Trophy class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                            <span class="relative z-10">Tear sheet</span>
                        </Link>
                    </div>

                    <div
                        v-if="top_winners === undefined || top_winners === null"
                        class="snitch-tear-board mt-3 grid gap-3 p-3"
                        aria-live="polite"
                    >
                        <div
                            v-for="index in 4"
                            :key="`winner-skel-${index}`"
                            class="flex items-start gap-3 px-0.5 pt-1"
                        >
                            <SnitchSkeleton
                                variant="polaroid"
                                width="9rem"
                                height="12rem"
                                :label="`Loading winner ${index}`"
                            />
                            <div class="flex min-w-0 flex-1 flex-col gap-2">
                                <SnitchSkeleton variant="line" width="60%" />
                                <SnitchSkeleton variant="line" width="90%" />
                                <SnitchSkeleton variant="line" width="75%" />
                            </div>
                        </div>
                    </div>
                    <div
                        v-else-if="top_winners.length"
                        class="snitch-tear-board mt-3 grid gap-3 p-3"
                    >
                        <article
                            v-for="(winner, index) in top_winners"
                            :key="winner.id"
                            class="snitch-polaroid relative"
                            :style="{
                                '--snitch-tilt': index % 2 === 0 ? '-1deg' : '1deg',
                            }"
                        >
                            <span
                                class="snitch-tape -top-2"
                                :class="index % 2 === 0 ? 'left-4' : 'right-4'"
                                aria-hidden="true"
                            />
                            <div class="flex items-start gap-3 px-0.5 pt-1">
                                <div class="snitch-dashboard-winner-media shrink-0">
                                    <div class="snitch-polaroid-frame overflow-hidden">
                                        <PlatformEmbed
                                            :embed="winner.post.embed"
                                            :cover-url="winner.post.cover_url"
                                            :media-url="winner.post.media_url"
                                            :post-url="winner.post.url"
                                            :platform="winner.post.platform"
                                            compact
                                        />
                                    </div>
                                </div>
                                <Link
                                    :href="feedShow.url(winner.post.id)"
                                    class="min-w-0 flex-1 space-y-2.5"
                                >
                                    <p class="text-xs uppercase tracking-wide text-snitch-ink/50">
                                        @{{ winner.post.tracked_account?.handle }} ·
                                        {{ platformLabel(winner.post.platform) }}
                                    </p>
                                    <div class="snitch-topic-row">
                                        <span
                                            v-for="pill in winnerStatPills(winner.score, winner.post.metrics)"
                                            :key="pill.key"
                                            class="snitch-topic-chip"
                                        >{{ pill.label }}</span>
                                    </div>
                                    <p
                                        v-if="winner.post.analysis?.hook"
                                        class="text-sm font-semibold text-snitch-ink"
                                    >
                                        {{ winner.post.analysis.hook }}
                                    </p>
                                    <p
                                        v-else-if="winner.post.analysis?.concept"
                                        class="text-sm font-semibold text-snitch-ink"
                                    >
                                        {{ winner.post.analysis.concept }}
                                    </p>
                                </Link>
                            </div>
                        </article>
                    </div>
                    <div
                        v-else
                        class="snitch-scrap relative mt-5 p-6"
                    >
                        <span class="snitch-tape right-6 -top-2" aria-hidden="true" />
                        <Trophy class="size-8 text-snitch-ink/35" aria-hidden="true" />
                        <p class="snitch-display mt-3 text-xl">No winners yet</p>
                        <p class="mt-2 text-sm text-snitch-ink/65">
                            Finish analysis, then rescore on the tear sheet.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
