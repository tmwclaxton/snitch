<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Clapperboard,
    ExternalLink,
    LoaderCircle,
    RefreshCw,
    Trash2,
    Trophy,
} from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import {
    index as competitorsIndex,
    sync,
} from '@/actions/App/Http/Controllers/CompetitorController';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import FeedContactCell from '@/components/FeedContactCell.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import RemoveCompetitorModal from '@/components/RemoveCompetitorModal.vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import SnitchSkeleton from '@/components/SnitchSkeleton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { PostMetrics } from '@/lib/metrics';
import { platformIconSrc, platformLabel } from '@/lib/platforms';
import type { SubscriptionSummary } from '@/types/global';

type Account = {
    id: number;
    platform: string;
    handle: string;
    display_name: string | null;
    avatar: string | null;
    url: string;
    posts_count?: number;
    last_synced_at: string | null;
    last_sync_status?: string | null;
    last_sync_error?: string | null;
    in_quota?: boolean;
};

type Post = {
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

type Winner = {
    id: number;
    score: number;
    why: string;
    how_to_copy: string;
    post: {
        id: number;
        platform: string;
        analysis?: { hook: string | null; concept?: string | null } | null;
    };
};

const props = defineProps<{
    account: Account;
    posts?: Post[] | null;
    winners?: Winner[] | null;
}>();

const postsList = computed<Post[]>(() => props.posts ?? []);
const winnersList = computed<Winner[]>(() => props.winners ?? []);
const postsLoaded = computed(() => Array.isArray(props.posts));
const winnersLoaded = computed(() => Array.isArray(props.winners));

defineOptions({
    layout: AppLayout,
});

const profileHref = computed(() => competitorsIndex.url());
const syncRequested = ref(false);
let syncPollTimer: ReturnType<typeof setInterval> | null = null;

const isSyncing = computed(
    () => props.account.last_sync_status === 'running' || syncRequested.value,
);

const lastSyncedLabel = computed(() => {
    if (!props.account.last_synced_at) {
        return null;
    }

    return `Last synced ${new Date(props.account.last_synced_at).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    })}`;
});

const syncErrorLabel = computed(() => {
    if (isSyncing.value || props.account.last_sync_status !== 'failed') {
        return null;
    }

    return props.account.last_sync_error
        ? `Sync failed: ${props.account.last_sync_error}`
        : 'Sync failed';
});

const removeDialogOpen = ref(false);

function clearSyncPoll(): void {
    if (syncPollTimer !== null) {
        clearInterval(syncPollTimer);
        syncPollTimer = null;
    }
}

function ensureSyncPoll(): void {
    if (!isSyncing.value) {
        clearSyncPoll();

        return;
    }

    if (syncPollTimer !== null) {
        return;
    }

    syncPollTimer = setInterval(() => {
        router.reload({
            only: ['account', 'posts', 'winners'],
            onFinish: () => {
                if (props.account.last_sync_status !== 'running') {
                    syncRequested.value = false;
                    clearSyncPoll();
                }
            },
        });
    }, 2500);
}

watch(isSyncing, () => {
    ensureSyncPoll();
}, { immediate: true });

onUnmounted(() => {
    clearSyncPoll();
});

const page = usePage();
const canRunBillable = computed(
    () => (page.props.subscription as SubscriptionSummary)?.can_run_billable === true,
);
const minRunBalancePence = computed(
    () => (page.props.subscription as SubscriptionSummary)?.min_run_balance_pence ?? 20,
);

const syncButtonLabel = computed(() => {
    if (isSyncing.value) {
        return `Sync running for @${props.account.handle}`;
    }

    if (!canRunBillable.value) {
        return `Balance must be more than ${minRunBalancePence.value}p to sync. Subscribe or top up on Billing.`;
    }

    return `Sync @${props.account.handle}`;
});

function syncNow(): void {
    if (isSyncing.value || !canRunBillable.value) {
        return;
    }

    syncRequested.value = true;

    router.post(sync.url(props.account.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            if (props.account.last_sync_status !== 'running') {
                syncRequested.value = false;
            }
        },
    });
}

function askRemove(): void {
    removeDialogOpen.value = true;
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head :title="`@${account.handle}`" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Link
                    :href="profileHref"
                    class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                >
                    <ArrowLeft class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                    <span class="relative z-10">Back to competitors</span>
                </Link>
                <div class="flex flex-wrap gap-2">
                    <a
                        v-if="account.url"
                        :href="account.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                    >
                        <ExternalLink class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                        <span class="relative z-10">Open on platform</span>
                    </a>
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-spot px-3 py-1.5 text-sm"
                        :disabled="isSyncing || !canRunBillable"
                        :title="syncButtonLabel"
                        :aria-label="syncButtonLabel"
                        @click="syncNow"
                    >
                        <span class="relative z-10 inline-flex items-center gap-1.5">
                            <LoaderCircle
                                v-if="isSyncing"
                                class="size-3.5 shrink-0 animate-spin"
                                aria-hidden="true"
                            />
                            <RefreshCw
                                v-else
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            {{ isSyncing ? 'Syncing…' : 'Sync' }}
                        </span>
                    </button>
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                        :aria-label="`Remove @${account.handle}`"
                        @click="askRemove"
                    >
                        <Trash2 class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                        <span class="relative z-10">Remove</span>
                    </button>
                </div>
            </div>

            <header class="mt-5 border-b border-snitch-ink/10 pb-6">
                <div class="flex min-w-0 items-center gap-4 sm:gap-5">
                    <SnitchAvatar
                        :src="account.avatar"
                        :name="account.display_name"
                        :handle="account.handle"
                        size="xl"
                        loading="eager"
                        :alt="account.display_name || account.handle"
                    />
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <img
                                :src="platformIconSrc(account.platform)"
                                :alt="`${platformLabel(account.platform)} logo`"
                                class="snitch-platform-logo size-4 shrink-0"
                                width="16"
                                height="16"
                            />
                            <p class="snitch-ink-label">
                                {{ platformLabel(account.platform) }}
                            </p>
                        </div>
                        <h1 class="snitch-display mt-1.5 truncate text-3xl text-snitch-ink sm:text-4xl">
                            {{ account.display_name || account.handle }}
                        </h1>
                        <p class="mt-1 text-sm text-snitch-ink/65 sm:text-base">
                            @{{ account.handle }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="isSyncing"
                    class="snitch-scrap relative mt-4 max-w-xl px-4 py-3"
                    aria-live="polite"
                    data-syncing="true"
                >
                    <div class="relative z-10 flex items-center gap-3">
                        <LoaderCircle
                            class="size-4 shrink-0 animate-spin text-snitch-ink/55"
                            aria-hidden="true"
                        />
                        <div>
                            <p class="text-sm font-medium text-snitch-ink">
                                Sync in progress
                            </p>
                            <p class="mt-0.5 text-xs text-snitch-ink/65">
                                Pulling recent reels. This page updates when the run finishes.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-sm text-snitch-ink/60">
                    <p>{{ account.posts_count ?? postsList.length }} reels tracked</p>
                    <p v-if="isSyncing">Last synced: in progress</p>
                    <p v-else-if="lastSyncedLabel">{{ lastSyncedLabel }}</p>
                </div>
                <p
                    v-if="syncErrorLabel"
                    class="mt-2 text-xs text-red-800/80"
                >
                    {{ syncErrorLabel }}
                </p>
            </header>

            <section class="mt-10">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="snitch-ink-label">Contact sheet</p>
                        <h2 class="snitch-display mt-1 text-2xl text-snitch-ink">
                            Recent posts
                        </h2>
                    </div>
                </div>

                <div
                    v-if="!postsLoaded"
                    class="snitch-contact-sheet mt-5 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
                    aria-live="polite"
                    aria-label="Loading recent posts"
                >
                    <SnitchSkeleton
                        v-for="row in 8"
                        :key="`post-skel-${row}`"
                        variant="polaroid"
                        width="100%"
                    />
                </div>
                <div
                    v-else-if="postsList.length"
                    class="snitch-contact-sheet snitch-contact-reveal mt-5 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
                >
                    <FeedContactCell
                        v-for="(post, index) in postsList"
                        :key="post.id"
                        :post="post"
                        :index="index"
                    />
                </div>
                <div
                    v-else
                    class="snitch-scrap relative mt-5 max-w-md p-6 text-center"
                >
                    <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
                    <LoaderCircle
                        v-if="isSyncing"
                        class="mx-auto size-8 animate-spin text-snitch-ink/35"
                        aria-hidden="true"
                    />
                    <Clapperboard
                        v-else
                        class="mx-auto size-8 text-snitch-ink/35"
                        aria-hidden="true"
                    />
                    <p class="snitch-display mt-3 text-xl">
                        {{
                            isSyncing
                                ? 'Syncing reels…'
                                : 'No reels yet'
                        }}
                    </p>
                    <p class="mt-2 text-sm text-snitch-ink/65">
                        {{
                            isSyncing
                                ? 'Hang tight - new posts will land here when the sync finishes.'
                                : 'Sync this account to pull recent short-form posts.'
                        }}
                    </p>
                </div>
            </section>

            <section class="mt-10">
                <p class="snitch-ink-label">From this account</p>
                <h2 class="snitch-display mt-1 text-2xl text-snitch-ink">
                    Winners
                </h2>

                <div
                    v-if="!winnersLoaded"
                    class="snitch-tear-board mt-5 grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3"
                    aria-live="polite"
                    aria-label="Loading winners"
                >
                    <SnitchSkeleton
                        v-for="row in 3"
                        :key="`winner-skel-${row}`"
                        variant="scrap"
                        height="6rem"
                    />
                </div>
                <div
                    v-else-if="winnersList.length"
                    class="snitch-tear-board mt-5 grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <Link
                        v-for="(winner, index) in winnersList"
                        :key="winner.id"
                        :href="feedShow.url(winner.post.id)"
                        class="snitch-polaroid relative block"
                        :style="{
                            '--snitch-tilt': index % 2 === 0 ? '-1deg' : '1.1deg',
                        }"
                    >
                        <span
                            class="snitch-tape -top-2"
                            :class="index % 2 === 0 ? 'left-4' : 'right-4'"
                            aria-hidden="true"
                        />
                        <div class="space-y-2 px-0.5">
                            <div class="flex items-center justify-between gap-2">
                                <span class="snitch-ink-label">#{{ index + 1 }}</span>
                                <span class="snitch-annotation text-xl">
                                    {{ winner.score.toFixed(1) }}
                                </span>
                            </div>
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
                    <span class="snitch-tape right-5 -top-2" aria-hidden="true" />
                    <Trophy class="size-8 text-snitch-ink/35" aria-hidden="true" />
                    <p class="snitch-display mt-3 text-xl">No winners from this account</p>
                    <p class="mt-2 text-sm text-snitch-ink/65">
                        After analysis clears your rules, winners land here.
                    </p>
                </div>
            </section>
        </div>

        <RemoveCompetitorModal
            v-model:open="removeDialogOpen"
            :account="account"
        />
    </div>
</template>
