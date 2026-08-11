<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Bot,
    Clapperboard,
    Hourglass,
    ListChecks,
    Trophy,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { show as agents } from '@/actions/App/Http/Controllers/AgentsController';
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
import { winnerStatPills } from '@/lib/metrics';
import type { PostMetrics } from '@/lib/metrics';
import { platformLabel } from '@/lib/platforms';

type RecentPost = {
    id: number;
    platform: string;
    type: string;
    url: string | null;
    media_url: string | null;
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

type TopWinner = {
    id: number;
    score: number;
    why: string;
    post: {
        id: number;
        platform: string;
        url: string | null;
        media_url: string | null;
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
    };
    activity?: ActivityPayload | null;
    recent_posts?: RecentPost[] | null;
    top_winners?: TopWinner[] | null;
}>();

defineOptions({
    layout: AppLayout,
});

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
        hint: lastSyncLabel.value,
        icon: Users as Component,
    },
    {
        label: 'Reels',
        value: props.stats.posts,
        href: feed.url(),
        hint: 'On the contact sheet',
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
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Dashboard" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl">
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
                        Counts, cadence, and winners - what rivals posted and what to remake.
                    </p>
                </div>

                <Link
                    :href="agents()"
                    class="snitch-scrap relative hidden max-w-[15.5rem] shrink-0 items-center gap-2 px-3 py-2.5 transition hover:-translate-y-0.5 md:flex"
                >
                    <Bot class="size-3.5 shrink-0 text-snitch-ink/50" aria-hidden="true" />
                    <div class="min-w-0">
                        <p class="snitch-ink-label leading-none">Agents</p>
                        <p class="snitch-display mt-0.5 text-sm leading-tight text-snitch-ink">
                            Connect your agent
                        </p>
                        <p class="mt-0.5 text-[0.65rem] leading-snug text-snitch-ink/55">
                            Cursor, Claude, Codex, and more.
                        </p>
                    </div>
                    <ArrowRight class="size-3.5 shrink-0 text-snitch-ink/40" aria-hidden="true" />
                </Link>
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

                <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(16rem,0.9fr)]">
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
                            <TimeOfDayChart
                                v-if="activity"
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

                    <div class="grid gap-4">
                        <div class="snitch-scrap relative p-5 pt-6">
                            <span class="snitch-tape right-4 -top-2" aria-hidden="true" />
                            <WeeklyVolumeChart
                                v-if="activity"
                                :weeks="activity.weekly"
                            />
                            <SnitchSkeleton
                                v-else
                                variant="scrap"
                                height="8rem"
                                label="Loading weekly volume chart"
                            />
                        </div>
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
                    </div>
                </div>
            </section>

            <div class="mt-10 grid gap-10 lg:grid-cols-2 lg:gap-8">
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
                        class="snitch-contact-sheet mt-5 grid grid-cols-2 sm:grid-cols-3"
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
                        class="snitch-contact-sheet snitch-contact-reveal mt-5 grid grid-cols-2 sm:grid-cols-3"
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
                        class="snitch-tear-board mt-5 grid gap-4 p-4 sm:p-5"
                        aria-live="polite"
                    >
                        <div
                            v-for="index in 4"
                            :key="`winner-skel-${index}`"
                            class="flex items-start gap-3 px-0.5 pt-1"
                        >
                            <SnitchSkeleton
                                variant="polaroid"
                                width="6rem"
                                height="8rem"
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
                        class="snitch-tear-board mt-5 grid gap-4 p-4 sm:p-5"
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
