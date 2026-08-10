<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AlertCircle, Ban, Hourglass } from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import AnalysisTermChip from '@/components/AnalysisTermChip.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import PlatformEmbed from '@/components/PlatformEmbed.vue';
import { exploreHrefForTerm } from '@/lib/analysisTerms';
import { metricPairs } from '@/lib/metrics';
import type { PostMetrics } from '@/lib/metrics';
import { platformIconSrc, platformLabel } from '@/lib/platforms';
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
        analysis?: AnalysisGlance | null;
        winner_insight?: { score: number } | null;
    };
    index: number;
    accountHref?: string | null;
}>();

const frameIndex = computed(() => String(props.index + 1).padStart(2, '0'));
const metrics = computed(() => metricPairs(props.post.metrics));
const analysisCompleted = computed(() => props.post.analysis?.status === 'completed');

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

const primaryTitle = computed(() =>
    postPrimaryTitle({
        caption: props.post.caption,
        hook: completedHook.value,
        concept: completedConcept.value,
        type: props.post.type,
        maxLength: 72,
    }),
);

const hookLine = computed(() => {
    // Caption owns the primary line when present; otherwise hook/concept is the title.
    if (!props.post.caption?.trim() || !completedHook.value) {
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
</script>

<template>
    <article class="snitch-contact-cell group">
        <div class="snitch-contact-cell-frame">
            <span class="snitch-contact-cell-index">{{ frameIndex }}</span>
            <span
                v-if="winnerScore"
                class="snitch-glance-winner"
                :title="`Winner · ${winnerScore}`"
            >
                ★ {{ winnerScore }}
            </span>
            <PlatformEmbed
                :embed="post.embed"
                :media-url="post.media_url"
                :post-url="post.url"
                :platform="post.platform"
                compact
            />
        </div>
        <div class="snitch-contact-cell-meta">
            <Link
                :href="feedShow.url(post.id)"
                class="snitch-contact-cell-meta-link block space-y-1"
            >
                <span class="snitch-ink-label inline-flex items-center gap-1.5">
                    <img
                        :src="platformIconSrc(post.platform)"
                        alt=""
                        class="snitch-platform-logo size-3.5 shrink-0"
                        width="14"
                        height="14"
                        loading="lazy"
                        decoding="async"
                    >
                    {{ platformLabel(post.platform) }} · {{ postTypeLabel(post.type) }}
                </span>
                <p class="snitch-annotation snitch-glance-title line-clamp-2">
                    {{ primaryTitle }}
                </p>
                <p
                    v-if="metrics.length"
                    class="snitch-glance-metrics"
                >
                    <span
                        v-for="metric in metrics"
                        :key="metric.key"
                        class="snitch-glance-metric"
                    >
                        <span class="tabular-nums">{{ metric.value }}</span>
                        {{ metric.label.toLowerCase() }}
                    </span>
                </p>
                <p
                    v-if="hookLine"
                    class="snitch-glance-hook line-clamp-2"
                >
                    {{ hookLine }}
                </p>
                <p
                    v-else-if="statusStamp"
                    class="snitch-glance-status inline-flex items-center gap-1"
                >
                    <component
                        :is="statusStamp.icon"
                        class="size-3 shrink-0 opacity-80"
                        aria-hidden="true"
                    />
                    {{ statusStamp.label }}
                </p>
            </Link>
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
                    :href="exploreHrefForTerm(tag)"
                />
            </div>
        </div>
    </article>
</template>
