<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Clapperboard,
    Hourglass,
    ListChecks,
    Trophy,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { index as backlog } from '@/actions/App/Http/Controllers/BacklogController';
import { index as competitors, show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import { index as feed, show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import { index as winners } from '@/actions/App/Http/Controllers/WinnerController';
import PlatformSplitChart from '@/components/dashboard/PlatformSplitChart.vue';
import PostingHeatmap from '@/components/dashboard/PostingHeatmap.vue';
import TimeOfDayChart from '@/components/dashboard/TimeOfDayChart.vue';
import WeeklyVolumeChart from '@/components/dashboard/WeeklyVolumeChart.vue';
import FeedContactCell from '@/components/FeedContactCell.vue';
import PlatformEmbed from '@/components/PlatformEmbed.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
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
    ctas: Array<{ term: string; count: number }>;
    cta_clicks: {
        clicks: number;
        posts_with_cta: number;
        posts: number;
    };
    growth: {
        followers: number;
        week_delta: number;
        week_pct: number | null;
        month_delta: number;
        month_pct: number | null;
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
    top_winners?: TopWinner[] | null;
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

const heatmapTotal = computed(() =>
    (props.activity?.heatmap ?? []).reduce((sum, day) => sum + day.count, 0),
);

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
    {
        label: 'Backlog',
        value: props.stats.analysis_backlog,
        href:
            props.stats.analysis_backlog > 0
                ? backlog.url()
                : props.stats.analysis_failed > 0
                  ? backlog.url({ query: { filter: 'failed' } })
                  : backlog.url(),
        hint:
            props.stats.analysis_failed > 0
                ? `${props.stats.analysis_failed} failed`
                : 'Awaiting analysis',
        icon: Hourglass as Component,
    },
]);

function accountHref(post: RecentPost): string | null {
    const id = post.tracked_account?.id;

    return id != null ? competitorShow.url(id) : null;
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-2 py-6 sm:px-3 sm:py-8">
        <Head title="Dashboard" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="snitch-app-canvas">
            <header class="flex items-center justify-between gap-6 border-b border-snitch-ink/10 pb-5">
                <div class="min-w-0">
                    <h1 class="snitch-display relative text-3xl text-snitch-ink sm:text-4xl">
                        <span
                            class="pointer-events-none absolute inset-0 translate-x-[2px] translate-y-[1px] text-snitch-spot opacity-55 mix-blend-multiply select-none dark:mix-blend-plus-lighter dark:opacity-70"
                            aria-hidden="true"
                        >Snitch</span>
                        <span class="relative">Snitch</span>
                    </h1>
                    <p class="mt-1.5 max-w-lg text-sm text-snitch-ink/65 sm:text-base">
                        Cadence, mix, captions, and winners - what rivals posted and what to remake.
                    </p>
                </div>
            </header>

            <div class="snitch-contact-reveal mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    v-for="(card, index) in statCards"
                    :key="card.label"
                    :href="card.href"
                    class="snitch-scrap relative block p-5 pt-6 transition hover:-translate-y-0.5"
                    :style="{
                        '--snitch-tilt': index % 2 === 0 ? '-0.8deg' : '0.9deg',
                    }"
                >
                    <span
                        class="snitch-tape"
                        :class="index % 2 === 0 ? 'left-5 -top-2' : 'right-4 -top-2'"
                        aria-hidden="true"
                    />
                    <span class="flex items-center justify-between gap-2">
                        <span class="snitch-ink-label">{{ card.label }}</span>
                        <component
                            :is="card.icon"
                            class="size-4 shrink-0 text-snitch-ink/45"
                            aria-hidden="true"
                        />
                    </span>
                    <p class="snitch-display mt-2 text-3xl tabular-nums text-snitch-ink">
                        {{ card.value }}
                    </p>
                    <p class="mt-1 text-xs text-snitch-ink/55">{{ card.hint }}</p>
                </Link>
            </div>

            <section class="mt-10">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="snitch-ink-label">Cadence</p>
                        <h2 class="snitch-display mt-1 text-2xl text-snitch-ink">
                            Snitch posting
                        </h2>
                    </div>
                    <p class="text-xs text-snitch-ink/55">
                        {{ heatmapTotal }} posts · last 16 weeks
                    </p>
                </div>

                <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1.55fr)_minmax(15rem,0.75fr)]">
                    <div class="grid gap-4">
                        <div class="snitch-scrap relative p-5 pt-6">
                            <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                            <p class="snitch-ink-label mb-3">Heat map</p>
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
                        <div class="snitch-scrap relative p-5 pt-6">
                            <span class="snitch-tape right-6 -top-2" aria-hidden="true" />
                            <WeeklyVolumeChart
                                v-if="activity"
                                :weeks="activity.weekly"
                            />
                            <SnitchSkeleton
                                v-else
                                variant="scrap"
                                height="12rem"
                                label="Loading weekly volume chart"
                            />
                        </div>
                    </div>

                    <div class="grid gap-4 self-start">
                        <div class="snitch-scrap relative p-5 pt-6">
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
                        <div class="snitch-scrap relative p-4 pt-5">
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
                    </div>
                </div>
            </section>

            <section class="mt-10">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="snitch-ink-label">Mix and captions</p>
                        <h2 class="snitch-display mt-1 text-2xl text-snitch-ink">
                            What they post
                        </h2>
                    </div>
                </div>

                <div
                    v-if="insights == null"
                    class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                    aria-live="polite"
                    aria-label="Loading mix and captions"
                >
                    <SnitchSkeleton
                        v-for="row in 4"
                        :key="`insight-skel-${row}`"
                        variant="scrap"
                        height="6rem"
                    />
                </div>
                <template v-else>
                    <div
                        v-if="insights.playbook.peak_hour_label || insights.playbook.top_format"
                        class="snitch-scrap relative mt-5 p-4 pt-5"
                    >
                        <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                        <p class="snitch-ink-label">Playbook</p>
                        <p class="mt-2 text-sm text-snitch-ink/80">
                            <span v-if="insights.playbook.peak_hour_label">
                                They post most around {{ insights.playbook.peak_hour_label }}.
                            </span>
                            <span v-if="insights.playbook.top_format">
                                Format lean is {{ insights.playbook.top_format }}.
                            </span>
                            <span v-if="insights.playbook.top_hashtag">
                                Top tag #{{ insights.playbook.top_hashtag }}.
                            </span>
                        </p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="snitch-scrap relative p-4 pt-5">
                            <p class="snitch-ink-label">Avg views</p>
                            <p class="snitch-display mt-1 text-2xl tabular-nums">
                                {{ formatFollowers(insights.engagement.avg_views) }}
                            </p>
                        </div>
                        <div class="snitch-scrap relative p-4 pt-5">
                            <p class="snitch-ink-label">Avg likes</p>
                            <p class="snitch-display mt-1 text-2xl tabular-nums">
                                {{ formatFollowers(insights.engagement.avg_likes) }}
                            </p>
                        </div>
                        <div class="snitch-scrap relative p-4 pt-5">
                            <p class="snitch-ink-label">Engagement rate</p>
                            <p class="snitch-display mt-1 text-2xl tabular-nums">
                                {{ insights.engagement.avg_rate.toFixed(2) }}%
                            </p>
                        </div>
                        <div class="snitch-scrap relative p-4 pt-5">
                            <p class="snitch-ink-label">Growth</p>
                            <p class="snitch-display mt-1 text-2xl tabular-nums">
                                {{ formatFollowers(insights.growth.followers) }}
                            </p>
                            <p class="mt-1 text-sm text-snitch-ink/65">
                                <span class="tabular-nums">{{ formatDelta(insights.growth.week_delta) }}</span>
                                this week
                                <span
                                    v-if="insights.growth.week_pct != null"
                                    class="tabular-nums"
                                >
                                    ({{ formatDelta(insights.growth.week_pct) }}%)
                                </span>
                            </p>
                        </div>
                        <div class="snitch-scrap relative p-4 pt-5">
                            <p class="snitch-ink-label">CTA clicks</p>
                            <p class="snitch-display mt-1 text-2xl tabular-nums">
                                {{ formatFollowers(insights.cta_clicks.clicks) }}
                            </p>
                            <p class="mt-1 text-sm text-snitch-ink/65">
                                {{ insights.cta_clicks.posts_with_cta }}
                                {{ insights.cta_clicks.posts_with_cta === 1 ? 'post' : 'posts' }}
                                with an ask
                            </p>
                        </div>
                        <div class="snitch-scrap relative p-4 pt-5">
                            <p class="snitch-ink-label">Format mix</p>
                            <p class="mt-1 text-sm text-snitch-ink/75">
                                <span
                                    v-for="row in insights.format_mix"
                                    :key="row.type"
                                    class="mr-2"
                                >
                                    {{ row.type }} {{ row.count }}
                                </span>
                                <span v-if="!insights.format_mix.length">No posts yet</span>
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div class="snitch-scrap relative p-4 pt-5">
                            <p class="snitch-ink-label">Hashtags</p>
                            <div
                                v-if="insights.hashtags.length"
                                class="mt-2 flex flex-wrap gap-1.5"
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
                                class="mt-2 text-sm text-snitch-ink/60"
                            >
                                No hashtags in recent captions.
                            </p>
                        </div>
                        <div class="snitch-scrap relative p-4 pt-5">
                            <p class="snitch-ink-label">Keywords</p>
                            <div
                                v-if="insights.keywords.length"
                                class="mt-2 flex flex-wrap gap-1.5"
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
                                class="mt-2 text-sm text-snitch-ink/60"
                            >
                                No caption keywords yet.
                            </p>
                        </div>
                    </div>

                    <div class="snitch-scrap relative mt-4 p-4 pt-5">
                        <p class="snitch-ink-label">Active ads</p>
                        <div
                            v-if="insights.ads.length"
                            class="mt-2 space-y-2"
                        >
                            <a
                                v-for="ad in insights.ads"
                                :key="ad.id"
                                :href="ad.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="block text-sm text-snitch-ink underline decoration-snitch-ink/20 underline-offset-4 transition hover:decoration-snitch-ink/50"
                            >
                                {{ ad.title }}
                            </a>
                        </div>
                        <p
                            v-else
                            class="mt-2 text-sm text-snitch-ink/60"
                        >
                            No library ads found yet. Sync a Facebook or Instagram snitch to pull the Ad Library.
                        </p>
                    </div>

                    <div class="snitch-scrap relative mt-4 p-4 pt-5">
                        <p class="snitch-ink-label">CTA language</p>
                        <div
                            v-if="insights.ctas.length"
                            class="mt-2 flex flex-wrap gap-1.5"
                        >
                            <span
                                v-for="row in insights.ctas"
                                :key="`cta-${row.term}`"
                                class="snitch-glance-tag"
                            >
                                {{ row.term }}
                                <span class="tabular-nums text-snitch-ink/50">{{ row.count }}</span>
                            </span>
                        </div>
                        <p
                            v-else
                            class="mt-2 text-sm text-snitch-ink/60"
                        >
                            No analysed CTAs yet. Finish reel analysis to fill this.
                        </p>
                    </div>
                </template>
            </section>

            <div class="mt-10 space-y-10">
                <section>
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="snitch-ink-label">Recent</p>
                            <h2 class="snitch-display mt-1 text-2xl text-snitch-ink">
                                Latest frames
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
                        class="snitch-contact-sheet snitch-contact-sheet-proof snitch-contact-sheet-proof-fill mt-5 grid"
                        aria-live="polite"
                    >
                        <div
                            v-for="index in 6"
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
                        class="snitch-contact-sheet snitch-contact-sheet-proof snitch-contact-sheet-proof-fill snitch-contact-reveal mt-5 grid"
                    >
                        <FeedContactCell
                            v-for="(post, index) in recent_posts"
                            :key="post.id"
                            :post="post"
                            :index="index"
                            :account-href="accountHref(post)"
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
                            Add snitches and sync to fill the board.
                        </p>
                        <Link
                            :href="competitors.url()"
                            class="snitch-btn snitch-btn-spot mt-4"
                        >
                            <span class="relative z-10 inline-flex items-center gap-2">
                                <Users class="size-3.5 shrink-0" aria-hidden="true" />
                                Snitches
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
                        class="snitch-tear-board mt-5 grid gap-4 p-3 sm:p-4 xl:grid-cols-2"
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
                        class="snitch-tear-board mt-5 grid gap-4 p-3 sm:p-4 xl:grid-cols-2"
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

            <section
                v-if="stats.analysis_backlog > 0 || stats.analysis_failed > 0"
                class="snitch-scrap relative mt-10 max-w-2xl p-5"
            >
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                <p class="flex items-center gap-2 snitch-ink-label">
                    <ListChecks class="size-3.5 shrink-0" aria-hidden="true" />
                    Analyse queue
                </p>
                <p class="snitch-display mt-2 text-xl text-snitch-ink">
                    {{ stats.analysis_backlog }} waiting
                    <span v-if="stats.analysis_failed > 0">
                        · {{ stats.analysis_failed }} failed
                    </span>
                </p>
                <p class="mt-2 text-sm text-snitch-ink/65">
                    Pending reels stay on the feed with a quiet status stamp until analysis finishes.
                </p>
                <Link
                    :href="backlog.url()"
                    class="snitch-btn snitch-btn-ghost mt-4 px-3 py-1.5 text-sm"
                >
                    <ArrowRight class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                    <span class="relative z-10">Open queue</span>
                </Link>
            </section>
        </div>
    </div>
</template>
