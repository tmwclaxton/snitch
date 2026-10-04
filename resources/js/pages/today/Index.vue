<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Check, ChevronDown, LoaderCircle, RefreshCw, Sun } from '@lucide/vue';
import { computed, ref } from 'vue';
import DailyBriefController from '@/actions/App/Http/Controllers/DailyBriefController';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import ExecStatTile from '@/components/dashboard/ExecStatTile.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAppDate } from '@/lib/dates';

defineOptions({
    layout: AppLayout,
});

type Inspiration = {
    post_id: number;
    url: string | null;
    handle: string | null;
};

type Action = {
    title: string;
    why: string | null;
    how: string | null;
    when: string | null;
    format: string | null;
    hook: string | null;
    related_handles: string[];
    related_post_ids: number[];
    inspiration: Inspiration[];
    done_at: string | null;
};

type PostRow = {
    post_id: number;
    url: string | null;
    handle: string | null;
    format: string;
    posted_label: string | null;
    caption: string | null;
    hook: string | null;
    hook_kind: string | null;
    likes: number | null;
    likes_hidden: boolean;
    comments: number;
    views: number | null;
    times_usual: number | null;
    times_usual_label: string;
};

type BigNumber = {
    label: string;
    value: string;
    note: string | null;
};

type CompetitorMove = {
    handle: string;
    followers_now: number | null;
    followers_change_7d: { label?: string | null; change?: number | null } | null;
    posts_last_24h: PostRow[];
    posts_last_7d_count: number;
    posts_last_7d_by_format: Record<string, number>;
    posts_last_7d: PostRow[];
    standout_winners: PostRow[];
    quiet: boolean;
    sync_empty: boolean;
    sync_status: string | null;
    days_since_last_post: number | null;
};

type Brief = {
    id: number;
    brief_date: string;
    status: string;
    headline: string;
    own_summary: string | null;
    competitor_summary: string | null;
    big_numbers: BigNumber[];
    actions: Action[];
    own_last_7_days: PostRow[];
    own_yesterday: PostRow[];
    unused_weekly_ideas: Array<{ position: number; format: string; hook: string | null }>;
    competitor_moves: CompetitorMove[];
    trends: string[];
    ads: { items: Array<{ handle: string; title: string | null; url: string | null }>; empty_label: string };
    watch: string[];
    data_freshness: Array<{ handle: string | null; last_synced_at: string | null; sync_status: string | null; sync_empty: boolean }>;
    generated_at: string | null;
};

type HistoryRow = {
    id: number;
    brief_date: string;
    generated_at: string | null;
};

const props = defineProps<{
    brief: Brief | null;
    history: HistoryRow[];
    date: string;
    generating: boolean;
    canRegenerate: boolean;
}>();

const historyOpen = ref(false);

const showHistoryMenu = computed(() => props.history.length >= 2);

const last24hPosts = computed(() => {
    const rows: PostRow[] = [];

    for (const move of props.brief?.competitor_moves ?? []) {
        rows.push(...(move.posts_last_24h ?? []));
    }

    return rows;
});

function regenerate(): void {
    if (!props.canRegenerate) {
        return;
    }

    router.post(DailyBriefController.generate.url(), {}, { preserveScroll: true });
}

function postMetrics(post: PostRow): string {
    const bits: string[] = [];

    if (post.likes_hidden) {
        bits.push('likes hidden');
    } else if (post.likes !== null) {
        bits.push(`${post.likes} likes`);
    }

    bits.push(`${post.comments} comments`);

    if (post.views) {
        bits.push(`${post.views} views`);
    }

    bits.push(post.times_usual_label);

    return bits.join(', ');
}

function formatMix(counts: Record<string, number>): string {
    return Object.entries(counts)
        .map(([format, count]) => `${count} ${format}${count === 1 ? '' : 's'}`)
        .join(', ');
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-2 py-6 sm:px-3 sm:py-8">
        <Head title="Today" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="snitch-app-canvas space-y-6">
            <header class="flex flex-wrap items-end justify-between gap-4 border-b border-snitch-ink/10 pb-5">
                <div>
                    <p class="snitch-ink-label">Daily summary</p>
                    <h1 class="snitch-display mt-1 text-3xl text-snitch-ink sm:text-4xl">
                        <span class="snitch-highlight">Today</span>
                    </h1>
                    <p class="mt-1.5 text-sm text-snitch-ink/65">
                        {{ formatAppDate(date) }}
                        <span v-if="brief?.generated_at">. Data refreshed {{ formatAppDate(brief.generated_at) }}.</span>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div v-if="showHistoryMenu" class="relative">
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost inline-flex items-center gap-1.5 px-3 py-1.5 text-sm"
                            @click="historyOpen = !historyOpen"
                        >
                            <span class="relative z-10 inline-flex items-center gap-1.5">
                                Previous days
                                <ChevronDown class="size-3.5" />
                            </span>
                        </button>
                        <div
                            v-if="historyOpen"
                            class="absolute right-0 z-20 mt-1 min-w-[14rem] border border-snitch-ink/15 bg-snitch-paper shadow-sm"
                        >
                            <Link
                                v-for="row in history"
                                :key="row.id"
                                :href="DailyBriefController.index.url({ query: { date: row.brief_date } })"
                                class="flex items-center justify-between gap-3 px-3 py-2 text-sm hover:bg-snitch-ink/5"
                                @click="historyOpen = false"
                            >
                                <span>{{ formatAppDate(row.brief_date) }}</span>
                            </Link>
                        </div>
                    </div>
                    <button
                        v-if="canRegenerate && brief"
                        type="button"
                        class="snitch-btn snitch-btn-ghost"
                        :disabled="generating"
                        @click="regenerate"
                    >
                        <span class="relative z-10 inline-flex items-center gap-2 text-sm">
                            <LoaderCircle v-if="generating" class="size-4 animate-spin" />
                            <RefreshCw v-else class="size-4" />
                            Regenerate
                        </span>
                    </button>
                </div>
            </header>

            <section v-if="brief" class="grid items-start gap-6">
                <p class="font-display text-2xl leading-snug text-snitch-ink sm:text-3xl">
                    {{ brief.headline }}
                </p>

                <div class="grid items-start gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <ExecStatTile
                        v-for="stat in brief.big_numbers"
                        :key="stat.label"
                        :label="stat.label"
                        :value="stat.value"
                    />
                </div>
                <div class="grid items-start gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <p
                        v-for="stat in brief.big_numbers"
                        :key="`${stat.label}-note`"
                        class="text-sm text-snitch-ink/65"
                    >
                        {{ stat.note }}
                    </p>
                </div>

                <section class="rounded border border-snitch-ink/10 bg-snitch-lift p-4 sm:p-5">
                    <h2 class="snitch-display text-2xl text-snitch-ink">
                        Action points
                    </h2>
                    <ul class="mt-4 grid items-start gap-4">
                        <li
                            v-for="(action, index) in brief.actions"
                            :key="index"
                            class="rounded border border-snitch-ink/10 bg-snitch-paper p-4"
                        >
                            <Form
                                v-bind="DailyBriefController.toggleAction.form({ brief: brief.id, index })"
                                class="grid items-start gap-3"
                            >
                                <button type="submit" class="flex items-start gap-3 text-left">
                                    <span
                                        class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center border border-snitch-ink/30"
                                        :class="action.done_at ? 'bg-snitch-ink text-snitch-paper' : 'bg-snitch-lift'"
                                    >
                                        <Check v-if="action.done_at" class="size-4" />
                                    </span>
                                    <span class="text-base font-medium text-snitch-ink">
                                        {{ action.title }}
                                    </span>
                                </button>
                            </Form>
                            <div class="mt-3 grid items-start gap-2 ps-9 text-sm text-snitch-ink/80">
                                <p v-if="action.how">
                                    <span class="font-medium text-snitch-ink">What:</span>
                                    {{ action.how }}
                                </p>
                                <p v-if="action.why">
                                    <span class="font-medium text-snitch-ink">Why:</span>
                                    {{ action.why }}
                                </p>
                                <p v-if="action.when">
                                    <span class="font-medium text-snitch-ink">When:</span>
                                    {{ action.when }}
                                </p>
                                <p v-if="action.hook">
                                    <span class="font-medium text-snitch-ink">Opening line:</span>
                                    {{ action.hook }}
                                </p>
                                <p v-if="action.inspiration.length" class="flex flex-wrap gap-x-3 gap-y-1">
                                    <span class="font-medium text-snitch-ink">Inspiration:</span>
                                    <a
                                        v-for="link in action.inspiration"
                                        :key="link.post_id"
                                        :href="link.url ?? feedShow.url(link.post_id)"
                                        class="underline decoration-snitch-ink/30 underline-offset-2"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {{ link.url ?? `Post ${link.post_id}` }}
                                    </a>
                                    <Link
                                        v-for="link in action.inspiration"
                                        :key="`feed-${link.post_id}`"
                                        :href="feedShow.url(link.post_id)"
                                        class="underline decoration-snitch-ink/30 underline-offset-2"
                                    >
                                        Open in Feed
                                    </Link>
                                </p>
                            </div>
                        </li>
                    </ul>
                </section>

                <section class="grid items-start gap-6 lg:grid-cols-2">
                    <article class="rounded border border-snitch-ink/10 bg-snitch-lift p-4 sm:p-5">
                        <h2 class="snitch-display text-2xl text-snitch-ink">Your last 7 days</h2>
                        <p v-if="brief.own_summary" class="mt-3 text-sm text-snitch-ink/80">
                            {{ brief.own_summary }}
                        </p>
                        <ul v-if="brief.own_last_7_days.length" class="mt-4 grid items-start gap-3">
                            <li
                                v-for="post in brief.own_last_7_days"
                                :key="post.post_id"
                                class="text-sm text-snitch-ink/80"
                            >
                                <p>
                                    {{ post.posted_label }}, {{ post.format }}
                                    <span v-if="post.hook"> "{{ post.hook }}"</span>
                                </p>
                                <p>{{ postMetrics(post) }}</p>
                                <p v-if="post.url" class="break-all">
                                    <a :href="post.url" class="underline decoration-snitch-ink/30 underline-offset-2" target="_blank" rel="noreferrer">{{ post.url }}</a>
                                    ·
                                    <Link :href="feedShow.url(post.post_id)" class="underline decoration-snitch-ink/30 underline-offset-2">Feed</Link>
                                </p>
                            </li>
                        </ul>
                        <p v-else class="mt-4 text-sm text-snitch-ink/65">Nothing posted in the last 7 days.</p>
                        <p v-if="brief.unused_weekly_ideas.length" class="mt-4 text-sm text-snitch-ink/80">
                            Unused Post this next ideas:
                            <span
                                v-for="idea in brief.unused_weekly_ideas"
                                :key="idea.position"
                            >
                                ({{ idea.position }}) {{ idea.format }} "{{ idea.hook }}"
                            </span>
                        </p>
                    </article>

                    <article class="rounded border border-snitch-ink/10 bg-snitch-lift p-4 sm:p-5">
                        <h2 class="snitch-display text-2xl text-snitch-ink">What competitors did</h2>
                        <p v-if="brief.competitor_summary" class="mt-3 text-sm text-snitch-ink/80">
                            {{ brief.competitor_summary }}
                        </p>
                        <div v-if="last24hPosts.length" class="mt-4">
                            <p class="text-sm font-medium text-snitch-ink">Last 24 hours ({{ last24hPosts.length }} posts)</p>
                            <ul class="mt-2 grid items-start gap-3">
                                <li v-for="post in last24hPosts" :key="post.post_id" class="text-sm text-snitch-ink/80">
                                    <p>@{{ post.handle }} ({{ post.posted_label }}): {{ post.format }} <span v-if="post.hook">"{{ post.hook }}"</span></p>
                                    <p>{{ postMetrics(post) }}</p>
                                    <p v-if="post.url" class="break-all">
                                        <a :href="post.url" class="underline decoration-snitch-ink/30 underline-offset-2" target="_blank" rel="noreferrer">{{ post.url }}</a>
                                        ·
                                        <Link :href="feedShow.url(post.post_id)" class="underline decoration-snitch-ink/30 underline-offset-2">Feed</Link>
                                    </p>
                                </li>
                            </ul>
                        </div>
                        <ul class="mt-4 grid items-start gap-4">
                            <li
                                v-for="move in brief.competitor_moves"
                                :key="move.handle"
                                class="rounded border border-snitch-ink/10 bg-snitch-paper p-3"
                            >
                                <p class="text-base font-medium text-snitch-ink">@{{ move.handle }}</p>
                                <p v-if="move.sync_empty" class="mt-1 text-sm text-snitch-ink/80">
                                    Snitch has never been able to load posts for this account.
                                </p>
                                <p v-else-if="move.quiet" class="mt-1 text-sm text-snitch-ink/80">
                                    No posts for {{ move.days_since_last_post }} days.
                                </p>
                                <p v-else class="mt-1 text-sm text-snitch-ink/80">
                                    {{ move.posts_last_7d_count }} posts
                                    <span v-if="Object.keys(move.posts_last_7d_by_format).length">
                                        ({{ formatMix(move.posts_last_7d_by_format) }})
                                    </span>
                                    <span v-if="move.followers_change_7d?.label">. {{ move.followers_change_7d.label }}</span>
                                </p>
                                <p
                                    v-for="winner in move.standout_winners"
                                    :key="winner.post_id"
                                    class="mt-2 text-sm text-snitch-ink"
                                >
                                    Standout: <span class="snitch-highlight">{{ winner.times_usual_label }}</span>
                                    <span v-if="winner.hook">, "{{ winner.hook }}"</span>
                                </p>
                            </li>
                        </ul>
                    </article>
                </section>

                <section class="grid items-start gap-6 lg:grid-cols-2">
                    <article class="rounded border border-snitch-ink/10 bg-snitch-lift p-4 sm:p-5">
                        <h2 class="snitch-display text-2xl text-snitch-ink">Trends</h2>
                        <ul class="mt-3 grid items-start gap-2 text-sm text-snitch-ink/80">
                            <li v-for="(line, index) in brief.trends" :key="index">{{ line }}</li>
                            <li v-if="!brief.trends.length">No strong format trend yet this week.</li>
                        </ul>
                    </article>
                    <article class="rounded border border-snitch-ink/10 bg-snitch-lift p-4 sm:p-5">
                        <h2 class="snitch-display text-2xl text-snitch-ink">Ads</h2>
                        <ul v-if="brief.ads.items.length" class="mt-3 grid items-start gap-2 text-sm text-snitch-ink/80">
                            <li v-for="(ad, index) in brief.ads.items" :key="index">
                                @{{ ad.handle }}: {{ ad.title }}
                                <a v-if="ad.url" :href="ad.url" class="underline decoration-snitch-ink/30 underline-offset-2" target="_blank" rel="noreferrer">{{ ad.url }}</a>
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-sm text-snitch-ink/80">{{ brief.ads.empty_label }}</p>
                    </article>
                </section>

                <section class="rounded border border-snitch-ink/10 bg-snitch-lift p-4 sm:p-5">
                    <h2 class="snitch-display text-2xl text-snitch-ink">Things to watch</h2>
                    <ul class="mt-3 grid items-start gap-2 text-sm text-snitch-ink/80">
                        <li v-for="(item, index) in brief.watch" :key="index">{{ item }}</li>
                    </ul>
                </section>

                <details class="rounded border border-snitch-ink/10 bg-snitch-lift p-4 text-sm text-snitch-ink/80">
                    <summary class="cursor-pointer text-base font-medium text-snitch-ink">Data freshness</summary>
                    <ul class="mt-3 grid items-start gap-2">
                        <li v-for="row in brief.data_freshness" :key="row.handle ?? 'unknown'">
                            @{{ row.handle }}: {{ row.sync_status ?? 'unknown' }}
                            <span v-if="row.last_synced_at">, last synced {{ row.last_synced_at }}</span>
                            <span v-if="row.sync_empty">, empty sync</span>
                        </li>
                    </ul>
                </details>
            </section>

            <section v-else class="rounded border border-snitch-ink/10 bg-snitch-lift p-8">
                <Sun class="size-8 text-snitch-ink" />
                <h2 class="snitch-display mt-4 text-2xl text-snitch-ink">Today</h2>
                <p class="mt-2 text-sm text-snitch-ink/80">
                    Your first daily summary arrives at 7am.
                </p>
                <button
                    v-if="canRegenerate"
                    type="button"
                    class="snitch-btn mt-4"
                    :disabled="generating"
                    @click="regenerate"
                >
                    <span class="relative z-10 inline-flex items-center gap-2 text-sm">
                        <LoaderCircle v-if="generating" class="size-4 animate-spin" />
                        Regenerate
                    </span>
                </button>
            </section>
        </div>
    </div>
</template>
