<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import { index as feedIndex } from '@/actions/App/Http/Controllers/FeedController';
import MarkdownText from '@/components/MarkdownText.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import PlatformEmbed from '@/components/PlatformEmbed.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { metricPairs } from '@/lib/metrics';
import type { PostMetrics } from '@/lib/metrics';
import { platformLabel } from '@/lib/platforms';
import { postPrimaryTitle, postTypeLabel } from '@/lib/posts';

type Analysis = {
    status: string;
    hook: string | null;
    hook_window_end_sec: number | null;
    visual_summary: string | null;
    idea: string | null;
    concept: string | null;
    topics: string[] | null;
    cta: string | null;
    how_to_copy: string | null;
    how_to_copy_html?: string | null;
    sfx: Array<{ at_sec?: number | null; label: string; role?: string | null }> | null;
    music: Record<string, unknown> | null;
    error_message?: string | null;
};

const props = defineProps<{
    post: {
        id: number;
        platform: string;
        type: string;
        url: string;
        caption: string | null;
        media_url: string | null;
        media_availability?: string | null;
        unavailable_reason?: string | null;
        posted_at?: string | null;
        metrics: PostMetrics | null;
        embed?: EmbedConfig | null;
        tracked_account?: {
            id?: number;
            handle: string;
            display_name: string | null;
        };
        analysis?: Analysis | null;
        winner_insight?: {
            score: number;
            why: string;
            how_to_copy: string;
            how_to_copy_html?: string | null;
        } | null;
    };
}>();

defineOptions({
    layout: AppLayout,
});

const metrics = computed(() => metricPairs(props.post.metrics));
const analysisDone = computed(() => props.post.analysis?.status === 'completed');
const isUnavailable = computed(
    () =>
        props.post.media_availability === 'unavailable' ||
        props.post.analysis?.status === 'unavailable',
);
const isFailed = computed(() => props.post.analysis?.status === 'failed');

const musicLine = computed(() => {
    const music = props.post.analysis?.music;

    if (!music || typeof music !== 'object') {
        return null;
    }

    const title = typeof music.title === 'string' ? music.title.trim() : '';
    const artist = typeof music.artist === 'string' ? music.artist.trim() : '';
    const role = typeof music.role === 'string' ? music.role.trim() : '';

    if (!title && !artist && !role) {
        return null;
    }

    const head = [title, artist].filter(Boolean).join(' · ');

    return role ? `${head || 'Track'} (${role})` : head;
});

const postedLabel = computed(() => {
    if (!props.post.posted_at) {
        return null;
    }

    return new Date(props.post.posted_at).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
});

const profileHref = computed(() => {
    const id = props.post.tracked_account?.id;

    return id != null ? competitorShow.url(id) : null;
});

const completedHook = computed(() =>
    analysisDone.value ? props.post.analysis?.hook?.trim() || null : null,
);

const completedConcept = computed(() =>
    analysisDone.value ? props.post.analysis?.concept?.trim() || null : null,
);

const primaryTitle = computed(() =>
    postPrimaryTitle({
        caption: props.post.caption,
        hook: completedHook.value,
        concept: completedConcept.value,
        type: props.post.type,
        maxLength: 140,
    }),
);

const showDisplayName = computed(() => {
    const display = props.post.tracked_account?.display_name?.trim();
    const handle = props.post.tracked_account?.handle?.trim();

    if (!display || !handle) {
        return false;
    }

    return display.toLowerCase() !== handle.toLowerCase();
});
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head :title="primaryTitle" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-5xl">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Link
                    :href="feedIndex.url()"
                    class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                >
                    Back to feed
                </Link>
                <a
                    v-if="post.url"
                    :href="post.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                >
                    Open on {{ platformLabel(post.platform) }}
                </a>
            </div>

            <header class="mt-5 border-b border-snitch-ink/10 pb-5">
                <p class="snitch-ink-label">
                    {{ platformLabel(post.platform) }} · {{ postTypeLabel(post.type) }}
                    <span v-if="postedLabel"> · {{ postedLabel }}</span>
                </p>
                <h1 class="snitch-display mt-2 text-3xl text-snitch-ink sm:text-4xl">
                    {{ primaryTitle }}
                </h1>
                <p class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm text-snitch-ink/65">
                    <Link
                        v-if="profileHref && post.tracked_account"
                        :href="profileHref"
                        class="font-medium text-snitch-ink underline decoration-snitch-ink/20 underline-offset-4 transition hover:decoration-snitch-spot"
                    >
                        @{{ post.tracked_account.handle }}
                    </Link>
                    <span v-else-if="post.tracked_account">
                        @{{ post.tracked_account.handle }}
                    </span>
                    <span
                        v-if="showDisplayName"
                        class="text-snitch-ink/50"
                    >
                        {{ post.tracked_account?.display_name }}
                    </span>
                </p>
            </header>

            <div class="mt-6 space-y-6">
                <div
                    v-if="post.winner_insight"
                    class="snitch-sticker"
                >
                    <p class="snitch-annotation text-xl">
                        Winner · {{ post.winner_insight.score.toFixed(1) }}
                    </p>
                    <p class="mt-2 text-sm text-snitch-ink/85">
                        {{ post.winner_insight.why }}
                    </p>
                    <div
                        v-if="post.winner_insight.how_to_copy"
                        class="mt-3 border-t border-dashed border-snitch-ink/15 pt-3"
                    >
                        <MarkdownText
                            :html="post.winner_insight.how_to_copy_html"
                            :source="post.winner_insight.how_to_copy"
                        />
                    </div>
                </div>

                <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,18rem)_minmax(0,1fr)] lg:gap-8">
                    <div>
                        <div
                            class="snitch-polaroid relative w-full"
                            style="--snitch-tilt: -0.6deg"
                        >
                            <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
                            <div class="snitch-polaroid-frame !aspect-auto overflow-hidden">
                                <PlatformEmbed
                                    :embed="post.embed"
                                    :media-url="post.media_url"
                                    :post-url="post.url"
                                    :platform="post.platform"
                                    :lazy="false"
                                />
                            </div>
                        </div>

                        <div
                            v-if="metrics.length"
                            class="snitch-metrics-strip mt-5"
                        >
                            <div
                                v-for="metric in metrics"
                                :key="metric.key"
                                class="snitch-metrics-strip-item"
                            >
                                <strong class="tabular-nums">{{ metric.value }}</strong>
                                <span>{{ metric.label }}</span>
                            </div>
                        </div>

                        <div
                            v-if="post.caption"
                            class="snitch-scrap relative mt-5 p-4"
                        >
                            <p class="snitch-ink-label">Caption</p>
                            <p class="relative z-10 mt-3 whitespace-pre-wrap text-sm leading-relaxed text-snitch-ink/80">
                                {{ post.caption }}
                            </p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <template v-if="analysisDone && post.analysis">
                            <div
                                v-if="post.analysis.concept"
                                class="snitch-sticker"
                            >
                                <p class="snitch-annotation text-xl">Concept</p>
                                <p class="mt-1 text-snitch-ink">{{ post.analysis.concept }}</p>
                            </div>

                            <div class="snitch-sticker">
                                <p class="snitch-annotation text-xl">Hook</p>
                                <p class="mt-1 text-snitch-ink">{{ post.analysis.hook }}</p>
                                <p
                                    v-if="post.analysis.hook_window_end_sec != null"
                                    class="mt-2 text-xs text-snitch-ink/50"
                                >
                                    Window ~{{ post.analysis.hook_window_end_sec }}s
                                </p>
                            </div>

                            <div
                                v-if="post.analysis.idea"
                                class="snitch-sticker"
                            >
                                <p class="snitch-annotation text-xl">Why it engages</p>
                                <p class="mt-1 text-snitch-ink">{{ post.analysis.idea }}</p>
                            </div>

                            <div
                                v-if="post.analysis.topics?.length"
                                class="snitch-sticker"
                            >
                                <p class="snitch-annotation text-xl">Topics</p>
                                <div class="snitch-topic-row mt-2">
                                    <span
                                        v-for="topic in post.analysis.topics"
                                        :key="topic"
                                        class="snitch-topic-chip"
                                    >{{ topic }}</span>
                                </div>
                            </div>

                            <div
                                v-if="post.analysis.visual_summary"
                                class="snitch-sticker"
                            >
                                <p class="snitch-annotation text-xl">Visual craft</p>
                                <p class="mt-1 text-sm text-snitch-ink/85">
                                    {{ post.analysis.visual_summary }}
                                </p>
                            </div>

                            <div
                                v-if="musicLine || post.analysis.sfx?.length"
                                class="snitch-sticker"
                            >
                                <p class="snitch-annotation text-xl">Music / SFX</p>
                                <p
                                    v-if="musicLine"
                                    class="mt-1 text-sm text-snitch-ink/85"
                                >
                                    {{ musicLine }}
                                </p>
                                <ul
                                    v-if="post.analysis.sfx?.length"
                                    class="mt-2 space-y-1 text-sm"
                                >
                                    <li
                                        v-for="(fx, i) in post.analysis.sfx"
                                        :key="i"
                                    >
                                        <span class="snitch-marker-underline">{{ fx.label }}</span>
                                        <span
                                            v-if="fx.role"
                                            class="text-snitch-ink/55"
                                        > · {{ fx.role }}</span>
                                        <span
                                            v-if="fx.at_sec != null"
                                            class="text-snitch-ink/45"
                                        >
                                            @ {{ fx.at_sec }}s
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            <div
                                v-if="post.analysis.how_to_copy || post.analysis.cta"
                                class="snitch-sticker"
                            >
                                <p class="snitch-annotation text-xl">How to remake</p>
                                <MarkdownText
                                    v-if="post.analysis.how_to_copy || post.analysis.how_to_copy_html"
                                    class="mt-1"
                                    :html="post.analysis.how_to_copy_html"
                                    :source="post.analysis.how_to_copy"
                                />
                                <p
                                    v-if="post.analysis.cta"
                                    class="mt-3 border-t border-dashed border-snitch-ink/15 pt-3 text-sm text-snitch-ink/70"
                                >
                                    CTA: {{ post.analysis.cta }}
                                </p>
                            </div>
                        </template>

                        <div
                            v-else-if="isUnavailable"
                            class="snitch-sticker"
                        >
                            <p class="snitch-annotation text-xl">Unavailable</p>
                            <p class="mt-2 text-sm text-snitch-ink/70">
                                {{
                                    post.unavailable_reason ||
                                        post.analysis?.error_message ||
                                        'Post or media is no longer available.'
                                }}
                            </p>
                        </div>

                        <div
                            v-else-if="isFailed"
                            class="snitch-sticker"
                        >
                            <p class="snitch-annotation text-xl">Analysis failed</p>
                            <p class="mt-2 text-sm text-snitch-ink/70">
                                {{ post.analysis?.error_message || 'We could not finish analyzing this reel.' }}
                            </p>
                        </div>

                        <div
                            v-else
                            class="snitch-sticker animate-pulse space-y-3"
                        >
                            <div class="h-4 w-24 bg-snitch-ink/10" />
                            <div class="h-16 bg-snitch-ink/10" />
                            <div class="h-16 bg-snitch-ink/10" />
                            <p class="text-sm text-snitch-ink/50">Analysis pending…</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
