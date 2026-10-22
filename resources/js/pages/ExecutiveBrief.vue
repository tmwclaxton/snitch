<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAppDate } from '@/lib/dates';
import { index as chatIndex } from '@/routes/chat';

defineOptions({
    layout: AppLayout,
});

type BriefPost = {
    post_id: number | null;
    url: string | null;
    snitch_url: string | null;
    handle: string | null;
    format: string | null;
    posted_label: string | null;
    hook: string | null;
    caption: string | null;
    times_usual: number | null;
    times_usual_label: string | null;
    thumbnail_url: string | null;
};

type OwnBullet = {
    text: string;
    posts: BriefPost[];
};

type CompetitorItem = {
    text: string;
    handle: string | null;
    posts: BriefPost[];
};

type Suggestion = {
    number: number;
    title: string;
    why: string;
    evidence: BriefPost[];
};

type Freshness = {
    line: string | null;
    failed_handles: string[];
};

type Signals = {
    cadence_days: Array<{ label: string; posted: boolean }>;
    own_posts_7d: number | null;
    format_mix: Array<{ label: string; count: number }>;
    best_slot_label: string | null;
    best_slot_sample_count: number | null;
};

type Brief = {
    empty: boolean;
    empty_reason: string | null;
    brief_date: string | null;
    generated_at: string | null;
    headline: string | null;
    summary: string | null;
    own_activity: OwnBullet[];
    competitor_activity: CompetitorItem[];
    suggestions: Suggestion[];
    signals?: Signals | null;
    freshness: Freshness;
    chat_url: string;
    competitors_url: string;
    curator_overrides: {
        summary: boolean;
        suggestions: boolean;
        updated_at: string | null;
    };
    insufficient_data: boolean;
};

const props = defineProps<{
    brief: Brief;
}>();

const dateLabel = computed(() => {
    if (! props.brief.brief_date) {
        return null;
    }

    return formatAppDate(props.brief.brief_date);
});

const headlineText = computed(() => {
    const headline = props.brief.headline?.trim();

    if (headline) {
        return headline;
    }

    return 'Nothing sharp enough for a headline yet.';
});

/** Split summary into short bullets for scanability. */
const summaryBullets = computed((): string[] => {
    const summary = props.brief.summary?.trim();

    if (! summary) {
        return ['Not enough stored data for a summary yet.'];
    }

    const parts = summary
        .split(/(?<=[.!?])\s+/)
        .map((part) => part.trim())
        .filter((part) => part !== '');

    return parts.length > 0 ? parts : [summary];
});

function postHref(post: BriefPost): string | null {
    return post.snitch_url || post.url || null;
}

function competitorTextWithoutHandle(item: CompetitorItem): string {
    if (! item.handle) {
        return item.text;
    }

    const prefix = '@' + item.handle;

    if (item.text.startsWith(prefix)) {
        return item.text.slice(prefix.length);
    }

    return ' ' + item.text;
}

/** Compact fact lines from a long own-activity sentence. */
function ownFactLines(text: string): string[] {
    return text
        .split(/\s*(?:·|-)\s*/)
        .map((part) => part.trim())
        .filter((part) => part !== '');
}

function timesUsualPct(post: BriefPost): number | null {
    if (typeof post.times_usual !== 'number' || ! Number.isFinite(post.times_usual) || post.times_usual <= 0) {
        return null;
    }

    // Cap the bar at 5x so outliers stay readable.
    return Math.min(100, Math.round((post.times_usual / 5) * 100));
}

const signals = computed(() => props.brief.signals ?? null);
const hasSignals = computed(() => {
    const s = signals.value;

    if (! s) {
        return false;
    }

    return (s.cadence_days?.length ?? 0) > 0
        || (s.format_mix?.length ?? 0) > 0
        || !! s.best_slot_label;
});
</script>

<template>
    <div>
        <Head title="Executive Brief" />

        <div class="flex flex-1 flex-col px-4 py-6 sm:px-6 md:px-8 md:py-8 lg:px-10">
            <article class="mx-auto w-full max-w-[1280px] text-base leading-[1.6] text-snitch-ink">
                <header class="space-y-4 border-b border-snitch-ink/15 pb-8">
                    <p class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/75">
                        Executive Brief
                    </p>
                    <h1 class="snitch-hero-display text-2xl leading-snug text-snitch-ink sm:text-3xl lg:text-4xl">
                        <span class="snitch-highlight">{{ headlineText }}</span>
                    </h1>
                    <p
                        v-if="dateLabel"
                        class="text-base text-snitch-ink/80"
                    >
                        <span class="font-medium text-snitch-ink">Brief date</span>
                        {{ dateLabel }}
                    </p>
                    <div
                        v-if="!brief.empty && hasSignals && signals"
                        class="flex flex-wrap items-end gap-6 pt-2"
                    >
                        <div
                            v-if="signals.cadence_days.length > 0"
                            class="space-y-2"
                        >
                            <p class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/80">
                                Your 7-day cadence
                                <span
                                    v-if="signals.own_posts_7d !== null"
                                    class="ml-1 font-normal normal-case tracking-normal text-snitch-ink/70"
                                >
                                    · {{ signals.own_posts_7d }} posts
                                </span>
                            </p>
                            <div class="flex items-end gap-1.5">
                                <div
                                    v-for="(day, idx) in signals.cadence_days"
                                    :key="`cadence-${idx}`"
                                    class="flex w-7 flex-col items-center gap-1"
                                >
                                    <span
                                        class="block w-full rounded-sm"
                                        :class="day.posted ? 'h-8 bg-snitch-ink' : 'h-3 bg-snitch-ink/15'"
                                        :title="day.posted ? `${day.label}: posted` : `${day.label}: quiet`"
                                    />
                                    <span class="text-xs font-medium text-snitch-ink/70">{{ day.label }}</span>
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="signals.format_mix.length > 0"
                            class="flex flex-wrap gap-2"
                        >
                            <span
                                v-for="row in signals.format_mix"
                                :key="row.label"
                                class="inline-flex items-center gap-1.5 rounded-full bg-snitch-ink/10 px-2.5 py-1 text-sm text-snitch-ink"
                            >
                                <span class="font-medium">{{ row.label }}</span>
                                <span class="text-snitch-ink/70">{{ row.count }}</span>
                            </span>
                        </div>
                        <p
                            v-if="signals.best_slot_label"
                            class="text-sm text-snitch-ink/80"
                        >
                            <span class="font-semibold text-snitch-ink">Best slot</span>
                            {{ signals.best_slot_label }}
                            <template v-if="signals.best_slot_sample_count !== null">
                                · n={{ signals.best_slot_sample_count }}
                            </template>
                        </p>
                    </div>
                </header>

                <section
                    v-if="brief.empty"
                    class="mt-10 rounded border border-snitch-ink/10 bg-snitch-lift p-6"
                >
                    <h2 class="snitch-hero-display text-xl text-snitch-ink">
                        Nothing to brief yet
                    </h2>
                    <p class="mt-3 text-base leading-[1.6] text-snitch-ink/90">
                        {{ brief.empty_reason }}
                    </p>
                    <Link
                        :href="competitors()"
                        class="snitch-btn snitch-btn-spot mt-5 inline-flex px-3 py-2 text-sm font-medium"
                    >
                        Go to Competitors
                    </Link>
                </section>

                <template v-else>
                    <div
                        class="mt-10 grid gap-12 min-[1000px]:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)] min-[1000px]:items-start min-[1000px]:gap-x-12 min-[1000px]:gap-y-0"
                    >
                        <!-- Left: summary + suggestions -->
                        <div class="space-y-12">
                            <section class="space-y-4">
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/80">
                                    Summary
                                </h2>
                                <ul class="space-y-2.5">
                                    <li
                                        v-for="(line, index) in summaryBullets"
                                        :key="`summary-${index}`"
                                        class="flex gap-3 text-base leading-[1.6] text-snitch-ink"
                                    >
                                        <span
                                            class="mt-2 size-1.5 shrink-0 rounded-full bg-snitch-ink"
                                            aria-hidden="true"
                                        />
                                        <span>{{ line }}</span>
                                    </li>
                                </ul>
                            </section>

                            <section class="space-y-5">
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/80">
                                    Our suggestions
                                </h2>
                                <ol class="space-y-4">
                                    <li
                                        v-for="suggestion in brief.suggestions"
                                        :key="suggestion.number"
                                        class="rounded border border-snitch-ink/12 bg-snitch-lift p-4 sm:p-5"
                                    >
                                        <p class="text-base font-semibold leading-[1.45] text-snitch-ink">
                                            <span class="mr-1.5 text-snitch-ink/55">{{ suggestion.number }}.</span>
                                            {{ suggestion.title }}
                                        </p>
                                        <p class="mt-2 text-base leading-[1.6] text-snitch-ink/90">
                                            <span class="font-semibold text-snitch-ink">Why:</span>
                                            {{ suggestion.why }}
                                        </p>
                                        <div
                                            v-if="suggestion.evidence.length > 0"
                                            class="mt-3 flex flex-wrap gap-2"
                                        >
                                            <a
                                                v-for="post in suggestion.evidence"
                                                :key="`ev-${suggestion.number}-${post.post_id}`"
                                                :href="postHref(post) || undefined"
                                                :target="post.url ? '_blank' : undefined"
                                                :rel="post.url ? 'noopener noreferrer' : undefined"
                                                class="flex items-center gap-2 rounded border border-snitch-ink/12 bg-snitch-paper px-2 py-1.5 text-sm text-snitch-ink no-underline"
                                            >
                                                <img
                                                    v-if="post.thumbnail_url"
                                                    :src="post.thumbnail_url"
                                                    alt=""
                                                    class="size-10 shrink-0 object-cover"
                                                />
                                                <span>
                                                    <span class="font-medium">
                                                        @{{ post.handle || 'post' }}
                                                    </span>
                                                    <span class="text-snitch-ink/70">
                                                        · {{ post.format || 'Post' }}
                                                        <template v-if="post.times_usual_label">
                                                            · {{ post.times_usual_label }}
                                                        </template>
                                                    </span>
                                                </span>
                                            </a>
                                        </div>
                                    </li>
                                </ol>
                            </section>
                        </div>

                        <!-- Right: own + competitors -->
                        <div class="space-y-12">
                            <section class="space-y-4">
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/80">
                                    What you've been doing
                                </h2>
                                <ul class="space-y-3">
                                    <li
                                        v-for="(bullet, index) in brief.own_activity"
                                        :key="`own-${index}`"
                                        class="rounded border border-snitch-ink/12 bg-snitch-lift p-4"
                                    >
                                        <ul class="space-y-1.5">
                                            <li
                                                v-for="(line, lineIndex) in ownFactLines(bullet.text)"
                                                :key="`own-line-${index}-${lineIndex}`"
                                                class="text-base leading-[1.55] text-snitch-ink"
                                            >
                                                {{ line }}
                                            </li>
                                        </ul>
                                        <div
                                            v-if="bullet.posts.length > 0"
                                            class="mt-3 grid grid-cols-2 gap-2"
                                        >
                                            <a
                                                v-for="post in bullet.posts"
                                                :key="`own-post-${post.post_id}-${post.url}`"
                                                :href="postHref(post) || undefined"
                                                :target="post.url ? '_blank' : undefined"
                                                :rel="post.url ? 'noopener noreferrer' : undefined"
                                                class="flex items-start gap-2 rounded border border-snitch-ink/10 bg-snitch-paper p-2 no-underline"
                                            >
                                                <img
                                                    v-if="post.thumbnail_url"
                                                    :src="post.thumbnail_url"
                                                    alt=""
                                                    class="size-12 shrink-0 object-cover"
                                                />
                                                <span
                                                    v-else
                                                    class="flex size-12 shrink-0 items-center justify-center bg-snitch-fog text-xs uppercase text-snitch-ink/60"
                                                >
                                                    {{ post.format || 'Post' }}
                                                </span>
                                                <span class="min-w-0 text-sm leading-snug text-snitch-ink">
                                                    <span class="font-medium">{{ post.format || 'Post' }}</span>
                                                    <span
                                                        v-if="post.posted_label"
                                                        class="block text-snitch-ink/70"
                                                    >
                                                        {{ post.posted_label }}
                                                    </span>
                                                    <span
                                                        v-if="post.times_usual_label"
                                                        class="mt-0.5 block text-snitch-ink/85"
                                                    >
                                                        {{ post.times_usual_label }}
                                                    </span>
                                                    <span
                                                        v-if="timesUsualPct(post) !== null"
                                                        class="mt-1 block h-1.5 w-full overflow-hidden rounded-full bg-snitch-ink/10"
                                                        :title="post.times_usual_label || undefined"
                                                    >
                                                        <span
                                                            class="block h-full rounded-full bg-snitch-ink"
                                                            :style="{ width: `${timesUsualPct(post)}%` }"
                                                        />
                                                    </span>
                                                </span>
                                            </a>
                                        </div>
                                    </li>
                                </ul>
                            </section>

                            <section class="space-y-4">
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/80">
                                    What competitors have been doing
                                </h2>
                                <ul class="space-y-4">
                                    <li
                                        v-for="(item, index) in brief.competitor_activity"
                                        :key="`comp-${index}`"
                                        class="rounded border border-snitch-ink/12 bg-snitch-lift p-4"
                                    >
                                        <p class="text-base leading-[1.55] text-snitch-ink">
                                            <template v-if="item.handle">
                                                <a
                                                    :href="`https://www.instagram.com/${item.handle}/`"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="font-semibold text-snitch-ink underline decoration-snitch-ink/35 underline-offset-2"
                                                >@{{ item.handle }}</a>{{ competitorTextWithoutHandle(item) }}
                                            </template>
                                            <template v-else>
                                                {{ item.text }}
                                            </template>
                                        </p>
                                        <div
                                            v-if="item.posts.length > 0"
                                            class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2"
                                        >
                                            <a
                                                v-for="post in item.posts"
                                                :key="`comp-post-${post.post_id}-${post.url}`"
                                                :href="postHref(post) || undefined"
                                                :target="post.url ? '_blank' : undefined"
                                                :rel="post.url ? 'noopener noreferrer' : undefined"
                                                class="flex gap-2.5 rounded border border-snitch-ink/10 bg-snitch-paper p-2.5 no-underline"
                                            >
                                                <img
                                                    v-if="post.thumbnail_url"
                                                    :src="post.thumbnail_url"
                                                    alt=""
                                                    class="size-14 shrink-0 object-cover"
                                                />
                                                <span
                                                    v-else
                                                    class="flex size-14 shrink-0 items-center justify-center bg-snitch-fog text-xs uppercase text-snitch-ink/60"
                                                >
                                                    {{ post.format || 'Post' }}
                                                </span>
                                                <span class="min-w-0 space-y-0.5 text-sm leading-snug text-snitch-ink">
                                                    <span class="block font-medium">
                                                        @{{ post.handle || item.handle }}
                                                    </span>
                                                    <span class="block text-snitch-ink/70">
                                                        {{ post.format || 'Post' }}
                                                        <template v-if="post.posted_label">
                                                            · {{ post.posted_label }}
                                                        </template>
                                                    </span>
                                                    <span
                                                        v-if="post.times_usual_label"
                                                        class="block text-snitch-ink/85"
                                                    >
                                                        {{ post.times_usual_label }}
                                                    </span>
                                                    <span
                                                        v-if="timesUsualPct(post) !== null"
                                                        class="mt-1 block h-1.5 w-full overflow-hidden rounded-full bg-snitch-ink/10"
                                                        :title="post.times_usual_label || undefined"
                                                    >
                                                        <span
                                                            class="block h-full rounded-full bg-snitch-ink"
                                                            :style="{ width: `${timesUsualPct(post)}%` }"
                                                        />
                                                    </span>
                                                    <span
                                                        v-if="post.hook || post.caption"
                                                        class="block text-snitch-ink/70"
                                                    >
                                                        {{ post.hook || post.caption }}
                                                    </span>
                                                </span>
                                            </a>
                                        </div>
                                    </li>
                                </ul>
                            </section>
                        </div>
                    </div>

                    <footer class="mt-14 space-y-3 border-t border-snitch-ink/15 pt-8 text-base text-snitch-ink/80">
                        <p v-if="dateLabel">
                            Brief date: {{ dateLabel }}
                        </p>
                        <p v-if="brief.freshness?.line">
                            {{ brief.freshness.line }}
                        </p>
                        <p>
                            Ask follow-ups in
                            <Link
                                :href="chatIndex()"
                                class="font-semibold text-snitch-ink underline decoration-snitch-ink/40 underline-offset-2"
                            >
                                Chat
                            </Link>.
                        </p>
                    </footer>
                </template>
            </article>
        </div>
    </div>
</template>
