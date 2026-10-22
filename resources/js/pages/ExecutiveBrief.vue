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
    expected?: string | null;
    evidence: BriefPost[];
};

type Freshness = {
    line: string | null;
    failed_handles: string[];
};

type Signals = {
    cadence_days: Array<{ label: string; posted: boolean }>;
    own_posts_7d: number | null;
    rival_median_posts_7d?: number | null;
    rival_cadence_label?: string | null;
    format_mix: Array<{ label: string; count: number }>;
    best_slot_label: string | null;
    best_slot_sample_count: number | null;
};

type KeyFigure = {
    label: string;
    value: string;
    detail: string | null;
    href?: string | null;
};

type Brief = {
    empty: boolean;
    empty_reason: string | null;
    brief_date: string | null;
    generated_at: string | null;
    headline: string | null;
    headline_detail?: string | null;
    narrative?: string | null;
    awaiting_narrative?: boolean;
    narrative_note?: string | null;
    summary: string | null;
    own_activity: OwnBullet[];
    competitor_activity: CompetitorItem[];
    suggestions: Suggestion[];
    signals?: Signals | null;
    key_figures?: KeyFigure[];
    show_cadence_visual?: boolean;
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

const narrativeText = computed(() => props.brief.narrative?.trim() || null);

const awaitingNarrative = computed(() => !! props.brief.awaiting_narrative);

const narrativeNote = computed(() => props.brief.narrative_note?.trim() || null);

const keyFigures = computed(() => props.brief.key_figures ?? []);

const signals = computed(() => props.brief.signals ?? null);

const showCadence = computed(() => {
    if (! props.brief.show_cadence_visual) {
        return false;
    }

    const s = signals.value;

    return !! s && (s.own_posts_7d !== null || (s.cadence_days?.length ?? 0) > 0);
});

const quietWeek = computed(() => (signals.value?.own_posts_7d ?? null) === 0);

const cadenceComparePct = computed(() => {
    const s = signals.value;

    if (! s || s.own_posts_7d === null) {
        return null;
    }

    const own = Math.max(0, s.own_posts_7d);
    const rival = Math.max(0, s.rival_median_posts_7d ?? 0);
    const max = Math.max(own, rival, 1);

    return {
        own: Math.round((own / max) * 100),
        rival: Math.round((rival / max) * 100),
        rivalMedian: s.rival_median_posts_7d,
    };
});

function postHref(post: BriefPost): string | null {
    return post.snitch_url || post.url || null;
}

function evidenceLabel(post: BriefPost): string {
    const handle = post.handle ? `@${post.handle}` : 'post';
    const nx = post.times_usual_label
        || (typeof post.times_usual === 'number' ? `${post.times_usual}x` : null);

    return nx ? `${handle} · ${nx}` : handle;
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
</script>

<template>
    <div>
        <Head title="Executive Brief" />

        <div class="flex flex-1 flex-col px-4 py-6 sm:px-5 md:px-6 md:py-8 lg:px-8">
            <article class="mx-auto w-full max-w-[1100px] text-[17px] leading-[1.65] text-snitch-ink">
                <header class="space-y-5 pb-2">
                    <p class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/70">
                        Executive Brief
                        <template v-if="dateLabel">
                            · {{ dateLabel }}
                        </template>
                    </p>
                    <h1 class="snitch-memo-headline snitch-hero-display max-w-[70ch] text-2xl leading-[1.2] text-snitch-ink sm:text-3xl">
                        <span class="snitch-highlight inline box-decoration-clone break-words [overflow-wrap:anywhere]">{{ headlineText }}</span>
                    </h1>
                </header>

                <section
                    v-if="brief.empty"
                    class="mt-10 max-w-[70ch]"
                >
                    <h2 class="snitch-hero-display text-xl text-snitch-ink">
                        Nothing to brief yet
                    </h2>
                    <p class="mt-3 text-[17px] leading-[1.65] text-snitch-ink/90">
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
                    <div class="mt-8 flex flex-col gap-10 lg:flex-row lg:items-start lg:gap-12">
                        <!-- Reading column -->
                        <div class="min-w-0 flex-1 space-y-10 lg:max-w-[740px]">
                            <p
                                v-if="narrativeText"
                                class="max-w-[70ch] text-[17px] leading-[1.7] text-snitch-ink sm:text-lg"
                            >
                                {{ narrativeText }}
                            </p>
                            <p
                                v-else-if="awaitingNarrative && narrativeNote"
                                class="max-w-[70ch] text-base leading-[1.65] text-snitch-ink/75"
                            >
                                {{ narrativeNote }}
                            </p>

                            <section
                                v-if="showCadence && signals"
                                class="space-y-3 border-t border-snitch-ink/12 pt-8"
                            >
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/70">
                                    Cadence
                                </h2>
                                <template v-if="quietWeek && cadenceComparePct">
                                    <p class="max-w-[70ch] text-base text-snitch-ink/85">
                                        <span class="font-semibold text-snitch-ink">0</span> posts this week vs a rival median of
                                        <span class="font-semibold text-snitch-ink">{{ cadenceComparePct.rivalMedian ?? 0 }}</span>.
                                    </p>
                                    <div class="max-w-xs space-y-2">
                                        <div class="flex items-center gap-2 text-sm text-snitch-ink/80">
                                            <span class="w-14 shrink-0">You</span>
                                            <span class="h-2 flex-1 rounded-sm bg-snitch-ink/10">
                                                <span
                                                    class="block h-full rounded-sm bg-snitch-ink/35"
                                                    :style="{ width: `${cadenceComparePct.own}%` }"
                                                />
                                            </span>
                                            <span class="w-6 tabular-nums">0</span>
                                        </div>
                                        <div class="flex items-center gap-2 text-sm text-snitch-ink/80">
                                            <span class="w-14 shrink-0">Rivals</span>
                                            <span class="h-2 flex-1 rounded-sm bg-snitch-ink/10">
                                                <span
                                                    class="block h-full rounded-sm bg-snitch-ink"
                                                    :style="{ width: `${cadenceComparePct.rival}%` }"
                                                />
                                            </span>
                                            <span class="w-6 tabular-nums">{{ cadenceComparePct.rivalMedian ?? 0 }}</span>
                                        </div>
                                    </div>
                                </template>
                                <template v-else>
                                    <p class="max-w-[70ch] text-base text-snitch-ink/85">
                                        You posted
                                        <span class="font-semibold text-snitch-ink">{{ signals.own_posts_7d ?? 0 }}</span>
                                        in the last 7 days
                                        <template v-if="signals.rival_median_posts_7d !== null && signals.rival_median_posts_7d !== undefined">
                                            · rival median
                                            <span class="font-semibold text-snitch-ink">{{ signals.rival_median_posts_7d }}</span>
                                        </template>
                                        .
                                    </p>
                                    <div
                                        v-if="signals.cadence_days.length > 0"
                                        class="flex items-end gap-1.5"
                                    >
                                        <div
                                            v-for="(day, idx) in signals.cadence_days"
                                            :key="`cadence-${idx}`"
                                            class="flex w-7 flex-col items-center gap-1"
                                        >
                                            <span
                                                class="block w-full rounded-sm"
                                                :class="day.posted ? 'h-8 bg-snitch-ink' : 'h-3 bg-snitch-ink/20'"
                                                :title="day.posted ? `${day.label}: posted` : `${day.label}: quiet`"
                                            />
                                            <span class="text-xs font-medium text-snitch-ink/65">{{ day.label }}</span>
                                        </div>
                                    </div>
                                </template>
                            </section>

                            <section class="space-y-5 border-t border-snitch-ink/12 pt-8">
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/70">
                                    What to do
                                </h2>
                                <ol class="max-w-[70ch] space-y-6">
                                    <li
                                        v-for="suggestion in brief.suggestions"
                                        :key="suggestion.number"
                                        class="flex gap-3"
                                    >
                                        <span
                                            class="mt-0.5 w-6 shrink-0 font-semibold tabular-nums text-snitch-ink/55"
                                            aria-hidden="true"
                                        >{{ suggestion.number }}.</span>
                                        <div class="min-w-0 space-y-2">
                                            <p class="font-semibold leading-[1.45] text-snitch-ink">
                                                {{ suggestion.title }}
                                            </p>
                                            <p class="text-[17px] leading-[1.65] text-snitch-ink/90">
                                                {{ suggestion.why }}
                                            </p>
                                            <p
                                                v-if="suggestion.expected"
                                                class="text-base text-snitch-ink/75"
                                            >
                                                Expected: {{ suggestion.expected }}
                                            </p>
                                            <a
                                                v-for="post in suggestion.evidence"
                                                :key="`ev-${suggestion.number}-${post.post_id}`"
                                                :href="postHref(post) || undefined"
                                                :target="post.url ? '_blank' : undefined"
                                                :rel="post.url ? 'noopener noreferrer' : undefined"
                                                class="inline-flex items-center gap-2 text-sm text-snitch-ink no-underline"
                                            >
                                                <img
                                                    v-if="post.thumbnail_url"
                                                    :src="post.thumbnail_url"
                                                    alt=""
                                                    class="size-8 shrink-0 object-cover"
                                                />
                                                <span
                                                    v-else
                                                    class="flex size-8 shrink-0 items-center justify-center bg-snitch-ink/10 text-[10px] uppercase text-snitch-ink/60"
                                                >
                                                    {{ (post.format || 'Post').slice(0, 1) }}
                                                </span>
                                                <span class="underline decoration-snitch-ink/30 underline-offset-2">
                                                    {{ evidenceLabel(post) }}
                                                </span>
                                            </a>
                                        </div>
                                    </li>
                                </ol>
                            </section>

                            <section class="space-y-5 border-t border-snitch-ink/12 pt-8">
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/70">
                                    What happened this week
                                </h2>
                                <div class="max-w-[70ch] space-y-6">
                                    <div
                                        v-for="(bullet, index) in brief.own_activity"
                                        :key="`own-${index}`"
                                        class="space-y-3"
                                    >
                                        <p class="text-[17px] leading-[1.65] text-snitch-ink">
                                            {{ bullet.text }}
                                        </p>
                                        <div
                                            v-if="bullet.posts.length > 0"
                                            class="flex flex-row flex-wrap gap-3"
                                        >
                                            <a
                                                v-for="post in bullet.posts"
                                                :key="`own-post-${post.post_id}-${post.url}`"
                                                :href="postHref(post) || undefined"
                                                :target="post.url ? '_blank' : undefined"
                                                :rel="post.url ? 'noopener noreferrer' : undefined"
                                                class="w-24 no-underline"
                                            >
                                                <img
                                                    v-if="post.thumbnail_url"
                                                    :src="post.thumbnail_url"
                                                    alt=""
                                                    class="size-24 object-cover"
                                                />
                                                <span
                                                    v-else
                                                    class="flex size-24 items-center justify-center bg-snitch-ink/10 text-xs uppercase text-snitch-ink/60"
                                                >
                                                    {{ post.format || 'Post' }}
                                                </span>
                                                <span class="mt-1 block text-xs leading-snug text-snitch-ink">
                                                    <span class="font-medium">@{{ post.handle || 'post' }}</span>
                                                    <span
                                                        v-if="post.times_usual_label || post.times_usual"
                                                        class="block text-snitch-ink/70"
                                                    >
                                                        {{ post.times_usual_label || `${post.times_usual}x` }}
                                                    </span>
                                                </span>
                                            </a>
                                        </div>
                                    </div>

                                    <div
                                        v-for="(item, index) in brief.competitor_activity"
                                        :key="`comp-${index}`"
                                        class="space-y-3"
                                    >
                                        <p class="text-[17px] leading-[1.65] text-snitch-ink">
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
                                            class="flex flex-row flex-wrap gap-3"
                                        >
                                            <a
                                                v-for="post in item.posts"
                                                :key="`comp-post-${post.post_id}-${post.url}`"
                                                :href="postHref(post) || undefined"
                                                :target="post.url ? '_blank' : undefined"
                                                :rel="post.url ? 'noopener noreferrer' : undefined"
                                                class="w-24 no-underline"
                                            >
                                                <img
                                                    v-if="post.thumbnail_url"
                                                    :src="post.thumbnail_url"
                                                    alt=""
                                                    class="size-24 object-cover"
                                                />
                                                <span
                                                    v-else
                                                    class="flex size-24 items-center justify-center bg-snitch-ink/10 text-xs uppercase text-snitch-ink/60"
                                                >
                                                    {{ post.format || 'Post' }}
                                                </span>
                                                <span class="mt-1 block text-xs leading-snug text-snitch-ink">
                                                    <span class="font-medium">@{{ post.handle || item.handle || 'post' }}</span>
                                                    <span
                                                        v-if="post.times_usual_label || post.times_usual"
                                                        class="block text-snitch-ink/70"
                                                    >
                                                        {{ post.times_usual_label || `${post.times_usual}x` }}
                                                    </span>
                                                </span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <footer class="space-y-2 border-t border-snitch-ink/12 pt-8 text-base text-snitch-ink/75">
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
                        </div>

                        <!-- Slim key-figures margin -->
                        <aside
                            v-if="keyFigures.length > 0"
                            class="w-full shrink-0 space-y-5 border-t border-snitch-ink/12 pt-8 lg:w-44 lg:border-t-0 lg:pt-0"
                        >
                            <h2 class="text-sm font-semibold uppercase tracking-wide text-snitch-ink/70">
                                Key figures
                            </h2>
                            <dl class="space-y-5">
                                <div
                                    v-for="(figure, index) in keyFigures"
                                    :key="`fig-${index}`"
                                >
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-snitch-ink/60">
                                        {{ figure.label }}
                                    </dt>
                                    <dd class="mt-1 font-hero text-2xl tracking-tight text-snitch-ink">
                                        <a
                                            v-if="figure.href"
                                            :href="figure.href"
                                            class="text-snitch-ink underline decoration-snitch-ink/30 underline-offset-2"
                                        >{{ figure.value }}</a>
                                        <template v-else>
                                            {{ figure.value }}
                                        </template>
                                    </dd>
                                    <dd
                                        v-if="figure.detail"
                                        class="mt-0.5 text-sm text-snitch-ink/70"
                                    >
                                        <a
                                            v-if="figure.href"
                                            :href="figure.href"
                                            class="text-snitch-ink/70 underline decoration-snitch-ink/25 underline-offset-2"
                                        >{{ figure.detail }}</a>
                                        <template v-else>
                                            {{ figure.detail }}
                                        </template>
                                    </dd>
                                </div>
                            </dl>
                        </aside>
                    </div>
                </template>
            </article>
        </div>
    </div>
</template>
