<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AlertCircle, Ban, Hourglass } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import AnalysisTermChip from '@/components/AnalysisTermChip.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import PlatformEmbed from '@/components/PlatformEmbed.vue';
import { metricPairs } from '@/lib/metrics';
import type { PostMetrics } from '@/lib/metrics';
import { platformIconSrc, productPlatformLabel } from '@/lib/platforms';
import {
    glanceTermChips,
    postPrimaryTitle,
    postTypeLabel,
} from '@/lib/posts';
import type { AnalysisTermLabel } from '@/lib/posts';

type AnalysisGlance = {
    status: string;
    hook?: string | null;
    concept?: string | null;
    topics?: string[] | null;
    custom_tags?: string[] | null;
    term_labels?: AnalysisTermLabel[] | null;
};

const props = defineProps<{
    post: {
        id: number;
        platform?: string | null;
        type: string;
        url: string | null;
        caption?: string | null;
        media_url: string | null;
        cover_url?: string | null;
        media_availability?: string | null;
        metrics?: PostMetrics | null;
        embed?: EmbedConfig | null;
        tracked_account?: {
            id?: number;
            handle: string;
            display_name?: string | null;
            platform?: string | null;
        } | null;
        social_account?: {
            platform?: string | null;
            handle?: string | null;
        } | null;
        analysis?: AnalysisGlance | null;
        winner_insight?: { score: number } | null;
    };
    index: number;
    accountHref?: string | null;
    compact?: boolean;
}>();

const platform = computed(
    () =>
        props.post.platform
        || props.post.tracked_account?.platform
        || props.post.social_account?.platform
        || null,
);

const frameIndex = computed(() => String(props.index + 1).padStart(2, '0'));
const metrics = computed(() => metricPairs(props.post.metrics));
const analysisCompleted = computed(() => props.post.analysis?.status === 'completed');
const captionExpanded = ref(false);

const captionSource = computed(() => props.post.caption?.trim() || '');

const displayCaption = computed(() => {
    if (captionSource.value) {
        return captionSource.value;
    }

    // Full hook/concept - never truncate with ellipsis.
    return postPrimaryTitle({
        caption: null,
        hook: completedHook.value,
        concept: completedConcept.value,
        type: props.post.type,
        maxLength: Number.POSITIVE_INFINITY,
    });
});

const captionNeedsToggle = computed(() => {
    const raw = captionSource.value;

    if (!raw) {
        return false;
    }

    return raw.length > 320 || raw.split(/\n/).length > 8;
});

const completedHook = computed(() => {
    if (!analysisCompleted.value) {
        return null;
    }

    return props.post.analysis?.hook?.trim() || null;
});

const completedConcept = computed(() => {
    if (!analysisCompleted.value) {
        return null;
    }

    return props.post.analysis?.concept?.trim() || null;
});

const tags = computed(() => {
    if (!analysisCompleted.value) {
        return [];
    }

    return glanceTermChips({
        concept: props.post.analysis?.concept,
        topics: props.post.analysis?.topics,
        termLabels: props.post.analysis?.term_labels,
        customTags: props.post.analysis?.custom_tags,
        limit: 3,
    });
});

const statusStamp = computed((): { label: string; icon: Component } | null => {
    if (
        props.post.analysis?.status === 'unavailable' ||
        props.post.media_availability === 'unavailable'
    ) {
        return { label: 'Unavailable', icon: Ban };
    }

    if (props.post.analysis?.status === 'failed') {
        return { label: 'Failed', icon: AlertCircle };
    }

    if (
        props.post.analysis?.status === 'pending' ||
        props.post.analysis?.status === 'processing' ||
        !props.post.analysis
    ) {
        return { label: 'Pending', icon: Hourglass };
    }

    return null;
});

const hookLine = computed(() => {
    if (!props.post.caption?.trim() || !completedHook.value) {
        return null;
    }

    // Hide seed / OCR junk (e.g. a lone "h") from the glance row.
    const meaningful = completedHook.value.replace(/[^a-z0-9]/gi, '');

    if (meaningful.length < 2) {
        return null;
    }

    return completedHook.value;
});

const winnerScore = computed(() => {
    const score = props.post.winner_insight?.score;

    if (typeof score !== 'number' || !Number.isFinite(score)) {
        return null;
    }

    return score.toFixed(1);
});

function toggleCaption(event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    captionExpanded.value = !captionExpanded.value;
}
</script>

<template>
    <article class="snitch-contact-cell group">
        <Link
            :href="feedShow.url(post.id)"
            class="snitch-contact-cell-hit"
        >
            <span class="sr-only">{{ displayCaption }}</span>
        </Link>
        <header class="snitch-contact-cell-header">
            <span class="snitch-contact-cell-index">{{ frameIndex }}</span>
            <span class="snitch-contact-cell-platform">
                <img
                    :src="platformIconSrc(platform ?? '')"
                    alt=""
                    class="snitch-platform-logo"
                    width="14"
                    height="14"
                    loading="lazy"
                    decoding="async"
                >
                <span class="snitch-contact-cell-platform-text">
                    {{ productPlatformLabel(platform) }} · {{ postTypeLabel(post.type) }}
                </span>
            </span>
            <span
                v-if="winnerScore"
                class="snitch-glance-winner"
                :title="`Winner · ${winnerScore}`"
            >
                ★ {{ winnerScore }}
            </span>
        </header>
        <div class="snitch-contact-cell-window">
            <div class="snitch-contact-cell-frame">
                <PlatformEmbed
                    :embed="post.embed"
                    :cover-url="post.cover_url"
                    :post-url="post.url"
                    :platform="platform ?? undefined"
                    compact
                />
            </div>
        </div>
        <div class="snitch-contact-cell-body">
            <div class="min-w-0">
                <p
                    class="snitch-glance-title whitespace-pre-wrap break-words"
                    :class="!captionExpanded && captionNeedsToggle ? 'snitch-glance-collapsed' : ''"
                >
                    {{ displayCaption }}
                </p>
                <button
                    v-if="captionNeedsToggle"
                    type="button"
                    class="relative z-10 mt-0.5 text-sm font-medium text-snitch-ink/55 underline-offset-2 hover:text-snitch-ink hover:underline"
                    @click="toggleCaption"
                >
                    {{ captionExpanded ? 'Show less' : 'Show more' }}
                </button>
            </div>
            <ul
                v-if="metrics.length"
                class="snitch-glance-metrics"
            >
                <li
                    v-for="metric in metrics"
                    :key="metric.key"
                    class="snitch-glance-metric"
                    :title="metric.value === 'hidden' ? 'Like count hidden on Instagram' : `${metric.value} ${metric.label}`"
                >
                    <template v-if="metric.value === 'hidden'">
                        <span class="snitch-glance-metric-value text-snitch-ink/45">likes hidden</span>
                    </template>
                    <template v-else>
                        <span class="snitch-glance-metric-value tabular-nums">{{ metric.value }}</span>
                        <span class="snitch-glance-metric-label">{{ metric.label }}</span>
                    </template>
                </li>
            </ul>
            <p
                v-if="hookLine && !compact"
                class="snitch-glance-hook whitespace-pre-wrap break-words"
            >
                {{ hookLine }}
            </p>
            <p
                v-else-if="statusStamp"
                class="snitch-glance-status"
            >
                <component
                    :is="statusStamp.icon"
                    class="size-3 shrink-0 opacity-80"
                    aria-hidden="true"
                />
                {{ statusStamp.label }}
            </p>
            <div class="snitch-contact-cell-footer">
                <Link
                    v-if="accountHref && post.tracked_account"
                    :href="accountHref"
                    class="snitch-glance-account-link"
                >
                    @{{ post.tracked_account.handle }}
                </Link>
                <span
                    v-else-if="post.tracked_account"
                    class="snitch-glance-account-link snitch-glance-account-link--static"
                >
                    @{{ post.tracked_account.handle }}
                </span>
                <div
                    v-if="tags.length"
                    class="snitch-glance-tags"
                >
                    <AnalysisTermChip
                        v-for="tag in tags"
                        :key="tag.key"
                        variant="glance"
                        :label="tag.label"
                        :dimension="tag.dimension"
                        :section="tag.section"
                        :slug="tag.slug"
                    />
                </div>
            </div>
        </div>
    </article>
</template>
