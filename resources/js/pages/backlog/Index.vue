<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Clapperboard, Hourglass } from '@lucide/vue';
import { computed, onMounted, onUnmounted } from 'vue';
import { index as backlogIndex } from '@/actions/App/Http/Controllers/BacklogController';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import FeedContactCell from '@/components/FeedContactCell.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import SnitchSkeleton from '@/components/SnitchSkeleton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { PostMetrics } from '@/lib/metrics';
import { dashboard } from '@/routes';

type BacklogPost = {
    id: number;
    platform: string;
    type: string;
    url: string | null;
    caption?: string | null;
    media_url: string | null;
    media_availability?: string | null;
    metrics?: PostMetrics | null;
    embed?: EmbedConfig | null;
    tracked_account?: {
        id?: number;
        handle: string;
        display_name?: string | null;
    } | null;
    analysis?: {
        status: string;
        hook?: string | null;
        concept?: string | null;
        topics?: string[] | null;
    } | null;
    winner_insight?: { score: number } | null;
};

type BacklogFilter = 'queue' | 'failed' | 'all';

type BacklogPage = {
    data: BacklogPost[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

const props = defineProps<{
    posts?: BacklogPage | null;
    filter: BacklogFilter;
    counts: {
        queue: number;
        failed: number;
    };
}>();

const postsLoaded = computed<boolean>(() =>
    Boolean(props.posts && Array.isArray(props.posts.data)),
);
const postsData = computed<BacklogPost[]>(() => props.posts?.data ?? []);
const paginationLinks = computed<BacklogPage['links']>(() => props.posts?.links ?? []);

defineOptions({
    layout: AppLayout,
});

const filters = computed(() => [
    { value: 'queue' as BacklogFilter, label: 'Waiting', count: props.counts.queue },
    { value: 'failed' as BacklogFilter, label: 'Failed', count: props.counts.failed },
    {
        value: 'all' as BacklogFilter,
        label: 'All open',
        count: props.counts.queue + props.counts.failed,
    },
]);

const emptyCopy = computed(() => {
    if (props.filter === 'failed') {
        return {
            title: 'No failed analyses',
            body: 'When analysis fails, the reel lands here so you can open it and read the error.',
        };
    }

    if (props.filter === 'all') {
        return {
            title: 'Queue is clear',
            body: 'Every synced reel has finished analysis. New syncs will show up here while they process.',
        };
    }

    return {
        title: 'Nothing in the queue',
        body: 'New reels appear here after sync while Snitch analyses them. Check the feed for completed frames.',
    };
});

const shouldPoll = computed(() => props.counts.queue > 0);

let pollTimer: ReturnType<typeof setInterval> | null = null;

function accountHref(post: BacklogPost): string | null {
    const id = post.tracked_account?.id;

    return id != null ? competitorShow.url(id) : null;
}

function filterHref(value: BacklogFilter): string {
    if (value === 'queue') {
        return backlogIndex.url();
    }

    return backlogIndex.url({ query: { filter: value } });
}

function paginationLabel(label: string): string {
    return label
        .replace('&laquo;', '«')
        .replace('&raquo;', '»')
        .replace('Previous', 'Prev')
        .replace('Next', 'Next');
}

onMounted(() => {
    if (!shouldPoll.value) {
        return;
    }

    pollTimer = setInterval(() => {
        router.reload({
            only: ['posts', 'counts', 'filter'],
        });
    }, 5000);
});

onUnmounted(() => {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
});
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Analyse queue" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl">
            <header class="border-b border-snitch-ink/10 pb-5">
                <Link
                    :href="dashboard()"
                    class="snitch-btn snitch-btn-ghost mb-4 px-3 py-1.5 text-sm"
                >
                    <ArrowLeft class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                    <span class="relative z-10">Dashboard</span>
                </Link>
                <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                    Analyse queue
                </h1>
                <p class="mt-1.5 max-w-2xl text-sm text-snitch-ink/65 sm:text-base">
                    Reels waiting on analysis after sync. This page refreshes while the queue runs.
                </p>
            </header>

            <div
                class="snitch-seg mt-6 flex flex-wrap gap-2"
                role="group"
                aria-label="Queue filter"
            >
                <Link
                    v-for="option in filters"
                    :key="option.value"
                    :href="filterHref(option.value)"
                    class="snitch-seg-item"
                    :class="filter === option.value ? 'snitch-seg-item-active' : ''"
                    preserve-scroll
                >
                    {{ option.label }}
                    <span class="tabular-nums">({{ option.count }})</span>
                </Link>
            </div>

            <p
                v-if="shouldPoll"
                class="mt-4 inline-flex items-center gap-2 text-xs uppercase tracking-wide text-snitch-ink/45"
            >
                <Hourglass class="size-3.5 shrink-0 animate-pulse" aria-hidden="true" />
                Auto-refreshing while {{ counts.queue }} reel{{ counts.queue === 1 ? '' : 's' }} wait
            </p>

            <div
                v-if="!postsLoaded"
                class="snitch-contact-sheet mt-6 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
                aria-label="Loading analyse queue"
            >
                <div class="snitch-contact-sheet-rail col-span-full">
                    <p>Analyse queue</p>
                    <p>Loading…</p>
                </div>
                <div
                    v-for="n in 8"
                    :key="`backlog-skel-${n}`"
                    class="p-2"
                >
                    <SnitchSkeleton variant="polaroid" />
                </div>
            </div>

            <div
                v-else-if="postsData.length"
                class="snitch-contact-sheet snitch-contact-reveal mt-6 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
            >
                <div class="snitch-contact-sheet-rail col-span-full">
                    <p>Analyse queue</p>
                    <p>{{ postsData.length }} on this page</p>
                </div>

                <FeedContactCell
                    v-for="(post, index) in postsData"
                    :key="post.id"
                    :post="post"
                    :index="index"
                    :account-href="accountHref(post)"
                />
            </div>

            <div
                v-else
                class="snitch-scrap relative mx-auto mt-8 max-w-md p-8 text-center sm:p-10"
            >
                <span class="snitch-tape left-8 -top-2" aria-hidden="true" />
                <Hourglass class="mx-auto size-8 text-snitch-ink/35" aria-hidden="true" />
                <p class="snitch-display mt-4 text-2xl text-snitch-ink">
                    {{ emptyCopy.title }}
                </p>
                <p class="mt-2 text-sm text-snitch-ink/65">
                    {{ emptyCopy.body }}
                </p>
                <Link
                    :href="filterHref('queue')"
                    v-if="filter !== 'queue'"
                    class="snitch-btn snitch-btn-ghost mt-5"
                >
                    <span class="relative z-10">View waiting</span>
                </Link>
                <p
                    v-else
                    class="mt-5 inline-flex items-center justify-center gap-2 text-xs uppercase tracking-wide text-snitch-ink/40"
                >
                    <Clapperboard class="size-3.5 shrink-0" aria-hidden="true" />
                    Queue clear
                </p>
            </div>

            <nav
                v-if="postsLoaded && paginationLinks.length > 3"
                class="mt-8 flex flex-wrap justify-center gap-2"
                aria-label="Pagination"
            >
                <template
                    v-for="(link, index) in paginationLinks"
                    :key="`${link.label}-${index}`"
                >
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                        :class="link.active ? 'snitch-btn-spot' : ''"
                        preserve-scroll
                    >
                        {{ paginationLabel(link.label) }}
                    </Link>
                    <span
                        v-else
                        class="px-3 py-1.5 text-sm text-snitch-ink/35"
                    >
                        {{ paginationLabel(link.label) }}
                    </span>
                </template>
            </nav>
        </div>
    </div>
</template>
