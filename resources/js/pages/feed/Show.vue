<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowLeft,
    Ban,
    ExternalLink,
    Megaphone,
    Music,
    ScrollText,
    Sparkles,
    Trophy,
    WandSparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import { index as feedIndex } from '@/actions/App/Http/Controllers/FeedController';
import AnalysisTermChip from '@/components/AnalysisTermChip.vue';
import MarkdownText from '@/components/MarkdownText.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import PlatformEmbed from '@/components/PlatformEmbed.vue';
import TranscriptModal from '@/components/TranscriptModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { analysisDimensionIcon, exploreHrefForTerm } from '@/lib/analysisTerms';
import { metricIcon } from '@/lib/metricIcons';
import { metricPairs } from '@/lib/metrics';
import type { PostMetrics } from '@/lib/metrics';
import { platformIconSrc, platformLabel } from '@/lib/platforms';
import {
    glanceTermChips,
    postPrimaryTitle,
    postTypeLabel,
} from '@/lib/posts';
import type { AnalysisTermLabel } from '@/lib/posts';

type Analysis = {
    status: string;
    hook: string | null;
    hook_window_end_sec: number | null;
    visual_summary: string | null;
    idea: string | null;
    concept: string | null;
    topics: string[] | null;
    custom_tags?: string[] | null;
    term_labels?: AnalysisTermLabel[] | null;
    cta: string | null;
    how_to_copy: string | null;
    how_to_copy_html?: string | null;
    transcript?: string | null;
    format_notes?: string | null;
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

const musicSourceLabel = computed(() => {
    const source = props.post.analysis?.music?.source;

    if (typeof source !== 'string' || source === '') {
        return null;
    }

    switch (source) {
        case 'platform':
            return 'Platform metadata';
        case 'acoustid':
            return 'AcoustID fingerprint';
        case 'audd':
            return 'AudD recognition';
        case 'model':
            return 'Model guess';
        default:
            return source.charAt(0).toUpperCase() + source.slice(1);
    }
});

const musicConfidencePct = computed(() => {
    const raw = props.post.analysis?.music?.confidence;

    if (typeof raw !== 'number' || !Number.isFinite(raw)) {
        return null;
    }

    const pct = Math.round(Math.max(0, Math.min(1, raw)) * 100);

    return pct > 0 ? `${pct}%` : null;
});

const musicIsrc = computed(() => {
    const raw = props.post.analysis?.music?.isrc;

    return typeof raw === 'string' && raw.trim() !== '' ? raw.trim() : null;
});

const spotifyTrackId = computed(() => {
    const music = props.post.analysis?.music;

    if (!music || typeof music !== 'object') {
        return null;
    }

    const direct = typeof music.spotify_track_id === 'string' ? music.spotify_track_id.trim() : '';

    if (/^[A-Za-z0-9]{22}$/.test(direct)) {
        return direct;
    }

    const url = typeof music.spotify_url === 'string' ? music.spotify_url : '';
    const match = url.match(/open\.spotify\.com\/(?:[a-z-]{2,10}\/)?track\/([A-Za-z0-9]{22})/);

    return match ? match[1] : null;
});

const spotifyUrl = computed(() => {
    if (spotifyTrackId.value) {
        return `https://open.spotify.com/track/${spotifyTrackId.value}`;
    }

    const raw = props.post.analysis?.music?.spotify_url;

    return typeof raw === 'string' && raw.trim() !== '' ? raw.trim() : null;
});

const spotifyEmbedUrl = computed(() => {
    if (!spotifyTrackId.value) {
        return null;
    }

    return `https://open.spotify.com/embed/track/${spotifyTrackId.value}?utm_source=snitch`;
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

const termChips = computed(() => {
    if (!analysisDone.value) {
        return [];
    }

    return glanceTermChips({
        topics: props.post.analysis?.topics,
        termLabels: props.post.analysis?.term_labels,
        customTags: props.post.analysis?.custom_tags,
        limit: 12,
        maxLength: null,
    });
});

const transcript = computed(() => {
    const raw = props.post.analysis?.transcript;

    return typeof raw === 'string' ? raw.trim() : '';
});

const transcriptWarning = computed(() => {
    const raw = props.post.analysis?.format_notes;

    return typeof raw === 'string' && raw.trim() !== '' ? raw.trim() : null;
});

const transcriptOpen = ref(false);

function openTranscript(): void {
    transcriptOpen.value = true;
}
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
                    <ArrowLeft class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                    <span class="relative z-10">Back to feed</span>
                </Link>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="transcript"
                        type="button"
                        class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                        data-test="open-transcript-button"
                        @click="openTranscript"
                    >
                        <ScrollText class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                        <span class="relative z-10">Transcript</span>
                    </button>
                    <a
                        v-if="post.url"
                        :href="post.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                    >
                        <ExternalLink class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                        <span class="relative z-10">Open on {{ platformLabel(post.platform) }}</span>
                    </a>
                </div>
            </div>

            <TranscriptModal
                v-model:open="transcriptOpen"
                :transcript="transcript"
                :warning="transcriptWarning"
            />

            <header class="mt-5 border-b border-snitch-ink/10 pb-5">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <p class="snitch-ink-label inline-flex flex-wrap items-center gap-1.5">
                        <img
                            :src="platformIconSrc(post.platform)"
                            alt=""
                            class="snitch-platform-logo size-3.5 shrink-0"
                            width="14"
                            height="14"
                        >
                        {{ platformLabel(post.platform) }} · {{ postTypeLabel(post.type) }}
                        <span v-if="postedLabel"> · {{ postedLabel }}</span>
                    </p>
                    <span
                        v-if="post.winner_insight"
                        class="inline-flex items-center gap-1 bg-snitch-spot/30 px-1.5 py-0.5 text-[0.68rem] font-semibold uppercase tracking-[0.14em] text-snitch-ink"
                        :title="`Winner score ${post.winner_insight.score.toFixed(1)}`"
                    >
                        <Trophy
                            class="size-3 shrink-0 opacity-80"
                            aria-hidden="true"
                        />
                        Winner
                    </span>
                </div>
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
                <div
                    v-if="termChips.length"
                    class="snitch-topic-row mt-3"
                >
                    <AnalysisTermChip
                        v-for="chip in termChips"
                        :key="chip.key"
                        :label="chip.label"
                        :dimension="chip.dimension"
                        :section="chip.section"
                        :slug="chip.slug"
                        :href="exploreHrefForTerm(chip)"
                    />
                </div>
            </header>

            <div class="mt-6 space-y-6">
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
                                <span class="inline-flex items-center gap-1">
                                    <component
                                        :is="metricIcon(metric.key)"
                                        class="size-3 shrink-0 opacity-70"
                                        aria-hidden="true"
                                    />
                                    {{ metric.label }}
                                </span>
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
                                <p class="snitch-annotation flex items-center gap-2 text-xl font-bold">
                                    <component
                                        :is="analysisDimensionIcon('concept')"
                                        class="size-5 shrink-0 opacity-80"
                                        aria-hidden="true"
                                    />
                                    Concept
                                </p>
                                <p class="mt-1 text-snitch-ink">{{ post.analysis.concept }}</p>
                            </div>

                            <div class="snitch-sticker">
                                <p class="snitch-annotation flex items-center gap-2 text-xl font-bold">
                                    <component
                                        :is="analysisDimensionIcon('hook_type')"
                                        class="size-5 shrink-0 opacity-80"
                                        aria-hidden="true"
                                    />
                                    Hook
                                </p>
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
                                <p class="snitch-annotation flex items-center gap-2 text-xl font-bold">
                                    <Sparkles
                                        class="size-5 shrink-0 opacity-80"
                                        aria-hidden="true"
                                    />
                                    Why it engages
                                </p>
                                <p class="mt-1 text-snitch-ink">{{ post.analysis.idea }}</p>
                            </div>

                            <div
                                v-if="post.analysis.visual_summary"
                                class="snitch-sticker"
                            >
                                <p class="snitch-annotation flex items-center gap-2 text-xl font-bold">
                                    <component
                                        :is="analysisDimensionIcon('visual_craft')"
                                        class="size-5 shrink-0 opacity-80"
                                        aria-hidden="true"
                                    />
                                    Visual craft
                                </p>
                                <p class="mt-1 text-sm text-snitch-ink/85">
                                    {{ post.analysis.visual_summary }}
                                </p>
                            </div>

                            <div
                                v-if="musicLine || post.analysis.sfx?.length"
                                class="snitch-sticker"
                            >
                                <p class="snitch-annotation flex items-center gap-2 text-xl font-bold">
                                    <Music
                                        class="size-5 shrink-0 opacity-80"
                                        aria-hidden="true"
                                    />
                                    Music / SFX
                                </p>
                                <p
                                    v-if="musicLine"
                                    class="mt-1 text-sm text-snitch-ink/85"
                                >
                                    {{ musicLine }}
                                </p>
                                <p
                                    v-if="musicLine && (musicSourceLabel || musicConfidencePct || musicIsrc)"
                                    class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-snitch-ink/55"
                                >
                                    <span v-if="musicSourceLabel" class="snitch-ink-label">
                                        {{ musicSourceLabel }}
                                    </span>
                                    <span v-if="musicConfidencePct" class="snitch-ink-label">
                                        {{ musicConfidencePct }} match
                                    </span>
                                    <span v-if="musicIsrc" class="snitch-ink-label">
                                        ISRC {{ musicIsrc }}
                                    </span>
                                </p>

                                <div
                                    v-if="spotifyEmbedUrl"
                                    class="mt-3"
                                >
                                    <iframe
                                        :src="spotifyEmbedUrl"
                                        class="w-full rounded-md border border-snitch-ink/10 shadow-[3px_3px_0_0_var(--snitch-spot)]"
                                        style="height: 80px"
                                        loading="lazy"
                                        allow="autoplay; clipboard-write; encrypted-media; picture-in-picture"
                                        title="Spotify preview"
                                    />
                                    <a
                                        :href="spotifyUrl ?? '#'"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="snitch-ink-label mt-2 inline-flex items-center gap-1 hover:text-snitch-ink"
                                    >
                                        Open on Spotify
                                        <ExternalLink class="size-3" aria-hidden="true" />
                                    </a>
                                </div>
                                <a
                                    v-else-if="spotifyUrl"
                                    :href="spotifyUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="snitch-ink-label mt-3 inline-flex items-center gap-1 hover:text-snitch-ink"
                                >
                                    Spotify
                                    <ExternalLink class="size-3" aria-hidden="true" />
                                </a>
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
                                v-if="post.analysis.how_to_copy || post.analysis.how_to_copy_html"
                                class="snitch-sticker"
                            >
                                <p class="snitch-annotation flex items-center gap-2 text-xl font-bold">
                                    <WandSparkles
                                        class="size-5 shrink-0 opacity-80"
                                        aria-hidden="true"
                                    />
                                    How to remake
                                </p>
                                <MarkdownText
                                    class="mt-1"
                                    :html="post.analysis.how_to_copy_html"
                                    :source="post.analysis.how_to_copy"
                                />
                            </div>

                            <div
                                v-if="post.analysis.cta"
                                class="snitch-sticker"
                            >
                                <p class="snitch-annotation flex items-center gap-2 text-xl font-bold">
                                    <Megaphone
                                        class="size-5 shrink-0 opacity-80"
                                        aria-hidden="true"
                                    />
                                    CTA
                                </p>
                                <p class="mt-1 text-sm text-snitch-ink/85">
                                    {{ post.analysis.cta }}
                                </p>
                            </div>
                        </template>

                        <div
                            v-else-if="isUnavailable"
                            class="snitch-sticker"
                        >
                            <p class="snitch-annotation flex items-center gap-2 text-xl font-bold">
                                <Ban
                                    class="size-5 shrink-0 opacity-80"
                                    aria-hidden="true"
                                />
                                Unavailable
                            </p>
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
                            <p class="snitch-annotation flex items-center gap-2 text-xl font-bold">
                                <AlertCircle
                                    class="size-5 shrink-0 opacity-80"
                                    aria-hidden="true"
                                />
                                Analysis failed
                            </p>
                            <p class="mt-2 text-sm text-snitch-ink/70">
                                {{ post.analysis?.error_message || 'We could not finish analysing this reel.' }}
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
