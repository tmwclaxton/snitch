<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { LoaderCircle, RefreshCw, SlidersHorizontal, Trophy } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import {
    rescore,
    rescoreStatus,
} from '@/actions/App/Http/Controllers/WinnerController';
import AnalysisTermChip from '@/components/AnalysisTermChip.vue';
import MarkdownText from '@/components/MarkdownText.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import PlatformEmbed from '@/components/PlatformEmbed.vue';
import SnitchSkeleton from '@/components/SnitchSkeleton.vue';
import type {
    WinnerRuleFormData,
    WinnerRulePreset,
} from '@/components/WinnerRulesForm.vue';
import WinnerRulesModal from '@/components/WinnerRulesModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { winnerStatPills } from '@/lib/metrics';
import type { PostMetrics } from '@/lib/metrics';
import { platformLabel } from '@/lib/platforms';
import { glanceTermChips } from '@/lib/posts';
import { useToastStore } from '@/stores/toastStore';

type RescoreRun = {
    id: string;
    status: 'pending' | 'processing' | string;
};

type RescoreStatusResponse = {
    status: string;
    error?: string | null;
    winner_count?: number | null;
};

type Winner = {
    id: number;
    score: number;
    why: string;
    how_to_copy: string;
    how_to_copy_html?: string | null;
    post: {
        id: number;
        url: string | null;
        media_url: string | null;
        platform: string;
        metrics?: PostMetrics | null;
        embed?: EmbedConfig | null;
        tracked_account?: { handle: string };
        analysis?: {
            hook: string | null;
            concept?: string | null;
            topics?: string[] | null;
        } | null;
    };
};

const props = defineProps<{
    winners?: Winner[] | null;
    rule: WinnerRuleFormData;
    presets: Record<string, WinnerRulePreset>;
    rescoreRun?: RescoreRun | null;
}>();

const winnersList = computed<Winner[]>(() => props.winners ?? []);
const winnersLoaded = computed(() => Array.isArray(props.winners));

defineOptions({
    layout: AppLayout,
});

const toast = useToastStore();
const rulesOpen = ref(false);
const rescoring = ref(false);
const rescoreMessage = ref('Rescoring…');
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const isRescoring = computed(() => rescoring.value);

function winnerTags(winner: {
    post: {
        analysis?: {
            topics?: string[] | null;
        } | null;
    };
}) {
    // Concept / hook already print above; chips are topics only, full length.
    return glanceTermChips({
        topics: winner.post.analysis?.topics,
        limit: 4,
        maxLength: null,
    });
}

function clearPoll(): void {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}

function startRescoring(run: RescoreRun): void {
    rescoring.value = true;
    rescoreMessage.value =
        run.status === 'processing' ? 'Rescoring…' : 'Queued…';
    void pollRescore(run.id);
}

async function pollRescore(id: string, attempt = 0): Promise<void> {
    // Job timeout is 300s; 200 × 1.5s ≈ 300s of client polling.
    if (attempt > 200) {
        rescoring.value = false;
        rescoreMessage.value = 'Timed out waiting for rescore.';
        toast.error('Rescore timed out. Try again in a moment.');

        return;
    }

    const response = await fetch(rescoreStatus.url(id), {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok && response.status !== 404) {
        throw new Error('Unable to check rescore status.');
    }

    const payload = (await response.json()) as RescoreStatusResponse;

    if (payload.status === 'completed') {
        rescoring.value = false;
        rescoreMessage.value = 'Rescoring…';
        toast.success(
            typeof payload.winner_count === 'number'
                ? `Tear sheet updated · ${payload.winner_count} winner${payload.winner_count === 1 ? '' : 's'}.`
                : 'Tear sheet updated.',
        );
        router.reload({ only: ['winners', 'rescoreRun'] });

        return;
    }

    if (payload.status === 'failed' || payload.status === 'missing') {
        rescoring.value = false;
        rescoreMessage.value = payload.error || 'Rescore failed.';
        toast.error(payload.error || 'Could not rescore winners.');

        return;
    }

    rescoreMessage.value =
        payload.status === 'processing' ? 'Rescoring…' : 'Queued…';

    pollTimer = setTimeout(() => {
        void pollRescore(id, attempt + 1);
    }, 1500);
}

function requestRescore(): void {
    if (rescoring.value) {
        return;
    }

    clearPoll();
    rescoring.value = true;
    rescoreMessage.value = 'Queued…';

    router.post(rescore.url(), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            const run = (page.props as { rescoreRun?: RescoreRun | null }).rescoreRun;

            if (run?.id) {
                startRescoring(run);

                return;
            }

            rescoring.value = false;
        },
        onError: () => {
            rescoring.value = false;
            toast.error('Could not start rescore.');
        },
    });
}

onMounted(() => {
    const run = props.rescoreRun;

    if (!run?.id) {
        return;
    }

    if (run.status !== 'pending' && run.status !== 'processing') {
        return;
    }

    startRescoring(run);
});

onUnmounted(() => {
    clearPoll();
});
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Winners" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                        Winners
                    </h1>
                    <p class="mt-1.5 text-sm text-snitch-ink/65 sm:text-base">
                        Preset {{ rule.preset }} · min {{ rule.min_views }} views /
                        {{ rule.min_likes }} likes
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-ghost"
                        :disabled="isRescoring"
                        @click="rulesOpen = true"
                    >
                        <SlidersHorizontal class="relative z-10 size-4 shrink-0" aria-hidden="true" />
                        <span class="relative z-10">Rules</span>
                    </button>
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-spot"
                        :disabled="isRescoring"
                        @click="requestRescore"
                    >
                        <span class="relative z-10 inline-flex items-center gap-2">
                            <LoaderCircle
                                v-if="isRescoring"
                                class="size-4 shrink-0 animate-spin"
                                aria-hidden="true"
                            />
                            <RefreshCw
                                v-else
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            {{ isRescoring ? rescoreMessage : 'Rescore' }}
                        </span>
                    </button>
                </div>
            </div>

            <div
                v-if="isRescoring"
                class="snitch-scrap relative mt-5 px-4 py-3 text-sm text-snitch-ink/80"
                role="status"
                aria-live="polite"
            >
                <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
                <p class="font-medium text-snitch-ink">
                    {{ rescoreMessage }} Keeping your current tear sheet until new scores land.
                </p>
            </div>

            <div
                v-if="!winnersLoaded"
                class="snitch-tear-board mt-8 space-y-4 p-4 sm:space-y-5 sm:p-6"
                aria-live="polite"
                aria-label="Loading winners"
            >
                <SnitchSkeleton
                    v-for="row in 3"
                    :key="`winner-skel-${row}`"
                    variant="scrap"
                    height="8rem"
                />
            </div>

            <div
                v-else
                class="snitch-tear-board mt-8 p-4 sm:p-6"
                :class="isRescoring ? 'opacity-70' : ''"
            >
                <div class="snitch-contact-reveal space-y-4 sm:space-y-5">
                    <article
                        v-for="(winner, index) in winnersList"
                        :key="winner.id"
                        class="snitch-tear-row relative border-b border-dashed border-snitch-ink/15 pb-5 last:border-b-0 last:pb-0 sm:pb-6"
                    >
                        <div class="snitch-tear-row-media">
                            <div
                                class="snitch-polaroid relative w-full"
                                :style="{
                                    '--snitch-tilt': index % 2 === 0 ? '-0.8deg' : '0.7deg',
                                }"
                            >
                                <span
                                    class="snitch-tape -top-2"
                                    :class="index % 2 === 0 ? 'left-4' : 'right-4'"
                                    aria-hidden="true"
                                />
                                <div class="snitch-polaroid-frame !aspect-auto overflow-hidden">
                                    <PlatformEmbed
                                        :embed="winner.post.embed"
                                        :media-url="winner.post.media_url"
                                        :post-url="winner.post.url"
                                        :platform="winner.post.platform"
                                        compact
                                    />
                                </div>
                            </div>
                        </div>

                        <Link
                            :href="feedShow.url(winner.post.id)"
                            class="snitch-tear-row-body relative z-10 block space-y-2.5"
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
                            <div
                                v-if="winnerTags(winner).length"
                                class="snitch-topic-row"
                            >
                                <AnalysisTermChip
                                    v-for="tag in winnerTags(winner)"
                                    :key="tag.key"
                                    :label="tag.label"
                                    :dimension="tag.dimension"
                                    :section="tag.section"
                                    :slug="tag.slug"
                                />
                            </div>
                            <div class="border-t border-dashed border-snitch-ink/15 pt-3">
                                <p class="snitch-annotation text-lg">How to copy</p>
                                <MarkdownText
                                    class="mt-1"
                                    :html="winner.how_to_copy_html"
                                    :source="winner.how_to_copy"
                                />
                            </div>
                        </Link>
                    </article>
                </div>

                <div
                    v-if="!winnersList.length"
                    class="snitch-scrap relative mx-auto max-w-md p-8 text-center"
                >
                    <span class="snitch-tape left-8 -top-2" aria-hidden="true" />
                    <Trophy class="mx-auto size-8 text-snitch-ink/35" aria-hidden="true" />
                    <p class="snitch-display mt-3 text-2xl">
                        {{ isRescoring ? 'Rescoring…' : 'No winners yet' }}
                    </p>
                    <p class="mt-2 text-sm text-snitch-ink/65">
                        {{
                            isRescoring
                                ? 'New scores will show here when the queue finishes.'
                                : 'Sync posts, wait for analysis, or loosen your rules.'
                        }}
                    </p>
                    <button
                        v-if="!isRescoring"
                        type="button"
                        class="snitch-btn snitch-btn-ghost mt-5"
                        @click="rulesOpen = true"
                    >
                        <SlidersHorizontal class="relative z-10 size-4 shrink-0" aria-hidden="true" />
                        <span class="relative z-10">Edit rules</span>
                    </button>
                </div>
            </div>
        </div>

        <WinnerRulesModal
            v-model:open="rulesOpen"
            :rule="rule"
            :presets="presets"
        />
    </div>
</template>
