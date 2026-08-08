<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as competitors, show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import { index as feed, show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import { index as winners } from '@/actions/App/Http/Controllers/WinnerController';
import FeedContactCell from '@/components/FeedContactCell.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import AppLayout from '@/layouts/AppLayout.vue';
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

const props = defineProps<{
    stats: {
        tracked_accounts: number;
        posts: number;
        winners: number;
        analysis_backlog: number;
        analysis_failed: number;
        last_synced_at: string | null;
    };
    recent_posts: RecentPost[];
    top_winners: Array<{
        id: number;
        score: number;
        why: string;
        post: {
            id: number;
            platform: string;
            tracked_account?: { handle: string } | null;
            analysis?: { hook: string | null; concept?: string | null } | null;
        };
    }>;
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

const statCards = computed(() => [
    {
        label: 'Accounts',
        value: props.stats.tracked_accounts,
        href: competitors.url(),
        hint: lastSyncLabel.value,
    },
    {
        label: 'Reels',
        value: props.stats.posts,
        href: feed.url(),
        hint: 'On the contact sheet',
    },
    {
        label: 'Winners',
        value: props.stats.winners,
        href: winners.url(),
        hint: 'Cleared your rules',
    },
    {
        label: 'Backlog',
        value: props.stats.analysis_backlog,
        href: feed.url(),
        hint:
            props.stats.analysis_failed > 0
                ? `${props.stats.analysis_failed} failed`
                : 'Awaiting analysis',
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
            <header class="border-b border-snitch-ink/10 pb-5">
                <h1 class="snitch-display relative text-3xl text-snitch-ink sm:text-4xl">
                    <span
                        class="pointer-events-none absolute inset-0 translate-x-[2px] translate-y-[1px] text-snitch-spot opacity-55 mix-blend-multiply select-none dark:mix-blend-plus-lighter dark:opacity-70"
                        aria-hidden="true"
                    >Snitch</span>
                    <span class="relative">Snitch</span>
                </h1>
                <p class="mt-1.5 max-w-lg text-sm text-snitch-ink/65 sm:text-base">
                    Live counts, recent frames, and winners worth remaking - not a link dump.
                </p>
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
                    <span class="snitch-ink-label">{{ card.label }}</span>
                    <p class="snitch-display mt-2 text-3xl tabular-nums text-snitch-ink">
                        {{ card.value }}
                    </p>
                    <p class="mt-1 text-xs text-snitch-ink/55">{{ card.hint }}</p>
                </Link>
            </div>

            <section class="mt-10">
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
                        Open feed
                    </Link>
                </div>

                <div
                    v-if="recent_posts.length"
                    class="snitch-contact-sheet snitch-contact-reveal mt-5 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6"
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
                    class="snitch-scrap relative mt-5 max-w-md p-6"
                >
                    <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
                    <p class="snitch-display text-xl">No frames yet</p>
                    <p class="mt-2 text-sm text-snitch-ink/65">
                        Add competitors and sync to fill the board.
                    </p>
                    <Link
                        :href="competitors.url()"
                        class="snitch-btn snitch-btn-spot mt-4 inline-flex"
                    >
                        <span class="relative z-10">Competitors</span>
                    </Link>
                </div>
            </section>

            <section class="mt-10">
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
                        Tear sheet
                    </Link>
                </div>

                <div
                    v-if="top_winners.length"
                    class="snitch-tear-board mt-5 grid gap-4 p-4 sm:grid-cols-2 sm:p-5"
                >
                    <Link
                        v-for="(winner, index) in top_winners"
                        :key="winner.id"
                        :href="feedShow.url(winner.post.id)"
                        class="snitch-polaroid relative block"
                        :style="{
                            '--snitch-tilt': index % 2 === 0 ? '-1deg' : '1deg',
                        }"
                    >
                        <span
                            class="snitch-tape -top-2"
                            :class="index % 2 === 0 ? 'left-4' : 'right-4'"
                            aria-hidden="true"
                        />
                        <div class="space-y-2 px-0.5 pt-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="snitch-ink-label">#{{ index + 1 }}</span>
                                <span class="snitch-annotation text-xl">
                                    {{ winner.score.toFixed(1) }}
                                </span>
                            </div>
                            <p class="text-xs uppercase tracking-wide text-snitch-ink/50">
                                @{{ winner.post.tracked_account?.handle }} ·
                                {{ platformLabel(winner.post.platform) }}
                            </p>
                            <p
                                v-if="winner.post.analysis?.concept"
                                class="text-sm font-medium text-snitch-ink"
                            >
                                {{ winner.post.analysis.concept }}
                            </p>
                            <p
                                v-else-if="winner.post.analysis?.hook"
                                class="text-sm text-snitch-ink/80"
                            >
                                {{ winner.post.analysis.hook }}
                            </p>
                            <p class="text-sm text-snitch-ink/75">{{ winner.why }}</p>
                        </div>
                    </Link>
                </div>
                <div
                    v-else
                    class="snitch-scrap relative mt-5 max-w-md p-6"
                >
                    <span class="snitch-tape right-6 -top-2" aria-hidden="true" />
                    <p class="snitch-display text-xl">No winners yet</p>
                    <p class="mt-2 text-sm text-snitch-ink/65">
                        Finish analysis, then rescore on the tear sheet.
                    </p>
                </div>
            </section>

            <section
                v-if="stats.analysis_backlog > 0 || stats.analysis_failed > 0"
                class="snitch-scrap relative mt-10 max-w-2xl p-5"
            >
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                <p class="snitch-ink-label">Analyze queue</p>
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
                    :href="feed.url()"
                    class="snitch-btn snitch-btn-ghost mt-4 inline-flex px-3 py-1.5 text-sm"
                >
                    Review feed
                </Link>
            </section>
        </div>
    </div>
</template>
