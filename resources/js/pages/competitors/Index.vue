<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Check,
    LoaderCircle,
    Plus,
    RefreshCw,
    Sparkles,
    Trash2,
    Users,
    X,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import {
    confirmSuggestions,
    dismissSuggestions,
    show as competitorShow,
    store,
    suggest,
    suggestStatus,
    sync,
} from '@/actions/App/Http/Controllers/CompetitorController';
import PlatformSelect from '@/components/PlatformSelect.vue';
import RemoveCompetitorModal from '@/components/RemoveCompetitorModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { platformIconSrc, platformLabel } from '@/lib/platforms';
import { lastSyncedLabel, nextSyncLabel } from '@/lib/syncSchedule';
import { useToastStore } from '@/stores/toastStore';
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
    sync_due?: boolean;
    next_sync_at?: string | null;
    in_quota?: boolean;
};

type Suggestion = {
    platform: string;
    handle: string;
    url: string;
    display_name: string;
    avatar: string | null;
    source?: string | null;
};

type SuggestStatusResponse = {
    id: string;
    status: 'pending' | 'processing' | 'completed' | 'failed' | 'missing';
    suggestions?: Suggestion[] | null;
    error?: string | null;
};

type SuggestRun = {
    id: string;
    status: 'pending' | 'processing';
};

type CompetitorCap = {
    plan?: string;
    plan_name?: string;
    competitor_limit?: number | null;
    competitors_used?: number;
    competitors_remaining?: number | null;
    over_quota_competitors?: number;
    on_trial?: boolean;
    can_upgrade?: boolean;
};

const props = defineProps<{
    accounts: Account[];
    platforms: string[];
    suggestions: Suggestion[];
    suggestRun?: SuggestRun | null;
    suggestError?: string | null;
    competitorCap?: CompetitorCap | null;
}>();

defineOptions({
    layout: AppLayout,
});

const toast = useToastStore();

const competitorsUsed = computed(() => {
    if (props.competitorCap?.competitors_used != null) {
        return props.competitorCap.competitors_used;
    }

    return props.accounts.length;
});

const selected = ref<Record<string, boolean>>({});
const localSuggestions = ref<Suggestion[]>([]);
const suggesting = ref(false);
const suggestMessage = ref(props.suggestError ?? '');
const removeDialogOpen = ref(false);
const accountToRemove = ref<Account | null>(null);
const syncingIds = ref<Record<number, boolean>>({});
const nowMs = ref(Date.now());
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let countdownTimer: ReturnType<typeof setInterval> | null = null;
let syncPollTimer: ReturnType<typeof setInterval> | null = null;

const hasRunningSync = computed(() =>
    props.accounts.some(
        (account) =>
            !!syncingIds.value[account.id] || account.last_sync_status === 'running',
    ),
);

const page = usePage();
const canRunBillable = computed(
    () => (page.props.subscription as SubscriptionSummary)?.can_run_billable === true,
);
const minRunBalancePence = computed(
    () => (page.props.subscription as SubscriptionSummary)?.min_run_balance_pence ?? 20,
);

const trackedKeys = computed(() => {
    const keys = new Set<string>();

    for (const account of props.accounts) {
        keys.add(`${account.platform}:${account.handle}`.toLowerCase());
    }

    return keys;
});

function withoutTracked(rows: Suggestion[]): Suggestion[] {
    return rows.filter(
        (item) => !trackedKeys.value.has(`${item.platform}:${item.handle}`.toLowerCase()),
    );
}

function syncSuggestionsFromProps(rows: Suggestion[]): void {
    const next = withoutTracked(rows);
    localSuggestions.value = next;

    const nextSelected: Record<string, boolean> = {};

    for (const item of next) {
        const key = suggestionKey(item);

        if (selected.value[key]) {
            nextSelected[key] = true;
        }
    }

    selected.value = nextSelected;
}

watch(
    () => props.suggestions,
    (rows) => {
        syncSuggestionsFromProps(rows);
    },
    { immediate: true, deep: true },
);

watch(
    () => props.accounts,
    () => {
        syncSuggestionsFromProps(localSuggestions.value);
    },
    { deep: true },
);

const form = useForm({
    platform: props.platforms[0] ?? 'instagram',
    handle: '',
    display_name: '',
});

const confirmForm = useForm({
    suggestions: [] as Suggestion[],
});

const selectedSuggestions = computed(() =>
    localSuggestions.value.filter((item) => selected.value[`${item.platform}:${item.handle}`]),
);

const allSelected = computed(
    () =>
        localSuggestions.value.length > 0 &&
        localSuggestions.value.every((item) => selected.value[`${item.platform}:${item.handle}`]),
);

function suggestionKey(item: Suggestion): string {
    return `${item.platform}:${item.handle}`;
}

function toggle(item: Suggestion): void {
    const key = suggestionKey(item);
    selected.value[key] = !selected.value[key];
}

function selectAll(): void {
    const next: Record<string, boolean> = {};

    for (const item of localSuggestions.value) {
        next[suggestionKey(item)] = true;
    }

    selected.value = next;
}

function clearSelection(): void {
    selected.value = {};
}

function toggleSelectAll(): void {
    if (allSelected.value) {
        clearSelection();
    } else {
        selectAll();
    }
}

function clearPoll(): void {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}

function clearSyncPoll(): void {
    if (syncPollTimer !== null) {
        clearInterval(syncPollTimer);
        syncPollTimer = null;
    }
}

function ensureSyncPoll(): void {
    if (!hasRunningSync.value) {
        clearSyncPoll();

        return;
    }

    if (syncPollTimer !== null) {
        return;
    }

    syncPollTimer = setInterval(() => {
        router.reload({
            only: ['accounts'],
            onFinish: () => {
                if (!hasRunningSync.value) {
                    clearSyncPoll();
                }
            },
        });
    }, 2500);
}

watch(hasRunningSync, () => {
    ensureSyncPoll();
}, { immediate: true });

onMounted(() => {
    countdownTimer = setInterval(() => {
        nowMs.value = Date.now();
    }, 30_000);

    if (localSuggestions.value.length > 0 && Object.keys(selected.value).length === 0) {
        selectAll();
        suggestMessage.value =
            suggestMessage.value || `Found ${localSuggestions.value.length} competitors.`;
    }

    const run = props.suggestRun;

    if (!run?.id) {
        return;
    }

    if (run.status !== 'pending' && run.status !== 'processing') {
        return;
    }

    suggesting.value = true;
    suggestMessage.value = run.status === 'processing' ? 'Finding…' : 'Queued…';
    void pollSuggestions(run.id);
});

onUnmounted(() => {
    clearPoll();
    clearSyncPoll();

    if (countdownTimer !== null) {
        clearInterval(countdownTimer);
        countdownTimer = null;
    }
});

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function applySuggestionRows(rows: Suggestion[] | null | undefined, selectNew = true): void {
    const next = withoutTracked(rows ?? []);
    const previousKeys = new Set(localSuggestions.value.map((item) => suggestionKey(item)));

    localSuggestions.value = next;

    if (!selectNew) {
        return;
    }

    const nextSelected = { ...selected.value };

    for (const item of next) {
        const key = suggestionKey(item);

        if (!previousKeys.has(key)) {
            nextSelected[key] = true;
        }
    }

    selected.value = nextSelected;
}

async function pollSuggestions(id: string, attempt = 0): Promise<void> {
    // Job timeout is 300s; 200 × 1.5s ≈ 300s of client polling.
    if (attempt > 200) {
        suggesting.value = false;
        suggestMessage.value = 'Timed out waiting for suggestions.';
        toast.error('Suggestion timed out. Try again in a moment.');

        return;
    }

    const response = await fetch(suggestStatus.url(id), {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok && response.status !== 404) {
        throw new Error('Unable to check suggestion status.');
    }

    const payload = (await response.json()) as SuggestStatusResponse;

    if (payload.status === 'completed') {
        applySuggestionRows(payload.suggestions);
        selected.value = {};
        suggesting.value = false;
        suggestMessage.value =
            localSuggestions.value.length > 0
                ? `Found ${localSuggestions.value.length} competitors.`
                : 'No verified competitors found.';

        if (localSuggestions.value.length === 0) {
            toast.error('No verified competitor accounts found. Try again later.');
        } else {
            selectAll();
            toast.success('Competitor picks ready.');
        }

        return;
    }

    if (payload.status === 'failed' || payload.status === 'missing') {
        applySuggestionRows(payload.suggestions);
        suggesting.value = false;
        suggestMessage.value =
            localSuggestions.value.length > 0
                ? `${payload.error || 'Suggestion stopped.'} Showing ${localSuggestions.value.length} found.`
                : payload.error || 'Suggestion failed.';
        toast.error(payload.error || 'Could not suggest competitors.');

        if (localSuggestions.value.length > 0) {
            selectAll();
        }

        return;
    }

    if (Array.isArray(payload.suggestions) && payload.suggestions.length > 0) {
        applySuggestionRows(payload.suggestions);
        suggestMessage.value = `Found ${localSuggestions.value.length} so far…`;
    } else {
        suggestMessage.value = payload.status === 'processing' ? 'Finding…' : 'Queued…';
    }

    pollTimer = setTimeout(() => {
        void pollSuggestions(id, attempt + 1);
    }, 1500);
}

async function requestSuggestions(): Promise<void> {
    if (suggesting.value) {
        return;
    }

    clearPoll();
    suggesting.value = true;
    suggestMessage.value = 'Finding…';
    localSuggestions.value = [];
    selected.value = {};

    try {
        const response = await fetch(suggest.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({}),
        });

        if (!response.ok) {
            const errorBody = (await response.json().catch(() => null)) as
                | { message?: string }
                | null;

            throw new Error(errorBody?.message || 'Could not start competitor suggestions.');
        }

        const payload = (await response.json()) as { id: string };
        await pollSuggestions(payload.id);
    } catch (error) {
        suggesting.value = false;
        suggestMessage.value = error instanceof Error ? error.message : 'Suggestion failed.';
        toast.error(suggestMessage.value);
    }
}

function submitConfirm(): void {
    const confirmed = selectedSuggestions.value;
    const confirmedKeys = new Set(confirmed.map((item) => suggestionKey(item).toLowerCase()));

    confirmForm
        .transform(() => ({
            suggestions: confirmed,
        }))
        .post(confirmSuggestions.url(), {
            preserveScroll: true,
            onSuccess: () => {
                localSuggestions.value = localSuggestions.value.filter(
                    (item) => !confirmedKeys.has(suggestionKey(item).toLowerCase()),
                );
                selected.value = {};
                suggestMessage.value =
                    localSuggestions.value.length > 0
                        ? `${localSuggestions.value.length} suggestions left.`
                        : '';
            },
        });
}

function dismiss(): void {
    router.post(dismissSuggestions.url(), {}, { preserveScroll: true });
}

function askRemove(account: Account): void {
    accountToRemove.value = account;
    removeDialogOpen.value = true;
}

function accountSyncDue(account: Account): boolean {
    return account.sync_due ?? nextSyncLabel(account, nowMs.value) === 'Due now';
}

function accountNextSyncLabel(account: Account): string {
    if (isAccountSyncing(account)) {
        return 'Syncing…';
    }

    return nextSyncLabel(account, nowMs.value);
}

function isAccountSyncing(account: Account): boolean {
    return !!syncingIds.value[account.id] || account.last_sync_status === 'running';
}

function emptyImportHint(account: Account): string | null {
    if ((account.posts_count ?? 0) > 0) {
        return null;
    }

    if (account.last_sync_status === 'failed') {
        return 'Last sync failed';
    }

    if (account.last_sync_status === 'success') {
        return 'No recent reels found';
    }

    return null;
}

function syncAccount(account: Account): void {
    if (syncingIds.value[account.id] || !canRunBillable.value) {
        return;
    }

    syncingIds.value = { ...syncingIds.value, [account.id]: true };

    router.post(sync.url(account.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            const next = { ...syncingIds.value };
            delete next[account.id];
            syncingIds.value = next;
        },
    });
}

function syncButtonTitle(account: Account): string {
    if (isAccountSyncing(account)) {
        return `Sync running for @${account.handle}`;
    }

    if (!canRunBillable.value) {
        return `Balance must be more than ${minRunBalancePence.value}p to sync. Subscribe or top up on Billing.`;
    }

    return `Sync @${account.handle}`;
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full min-w-0 px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Competitors" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto w-full min-w-0 max-w-6xl">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                        Tracked accounts
                    </h1>
                    <p class="mt-1.5 text-sm text-snitch-ink/65 sm:text-base">
                        Accounts you watch across Instagram, TikTok, YouTube Shorts, Facebook, and LinkedIn.
                    </p>
                    <p
                        v-if="competitorsUsed > 0"
                        class="mt-2 text-xs uppercase tracking-[0.14em] text-snitch-ink/55"
                    >
                        {{ competitorsUsed }} tracked
                    </p>
                </div>
                <div class="flex min-w-0 flex-col gap-1 sm:items-end">
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-ghost w-full sm:w-auto"
                        :disabled="suggesting"
                        @click="requestSuggestions"
                    >
                        <LoaderCircle
                            v-if="suggesting"
                            class="relative z-10 size-4 shrink-0 animate-spin"
                            aria-hidden="true"
                        />
                        <Sparkles
                            v-else
                            class="relative z-10 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="relative z-10">
                            {{ suggesting ? 'Finding…' : 'Suggest competitors' }}
                        </span>
                    </button>
                    <p
                        v-if="suggestMessage"
                        class="snitch-annotation text-base text-snitch-ink/70 sm:text-right"
                        aria-live="polite"
                    >
                        {{ suggestMessage }}
                    </p>
                </div>
            </div>

            <div
                v-if="suggesting"
                class="snitch-scrap relative mt-6 overflow-hidden p-5 pt-6 sm:mt-8 sm:p-6 sm:pt-8"
                aria-live="polite"
            >
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                <div class="relative z-10 flex items-center gap-4">
                    <div
                        class="h-14 w-14 shrink-0 animate-pulse bg-snitch-spot"
                        style="
                            clip-path: polygon(4% 0, 100% 3%, 96% 100%, 0 97%);
                            filter: drop-shadow(3px 3px 0 color-mix(in oklab, var(--snitch-ink) 35%, transparent));
                        "
                        aria-hidden="true"
                    />
                    <div>
                        <p class="snitch-display text-2xl text-snitch-ink">
                            Scraping the neighborhood
                        </p>
                        <p class="mt-1 text-sm text-snitch-ink/65">
                            Searching the web for rivals, then verifying real public profiles.
                            Verified picks appear below as they land.
                        </p>
                    </div>
                </div>
            </div>

            <form
                class="snitch-scrap snitch-add-account relative mt-6 p-4 pt-5 sm:mt-8 sm:p-5 sm:pt-6"
                @submit.prevent="form.post(store.url(), { onSuccess: () => form.reset('handle', 'display_name') })"
            >
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />

                <div
                    class="relative z-10 flex flex-col gap-3 sm:flex-row sm:items-end sm:gap-3"
                >
                    <label class="block min-w-0 sm:w-44 sm:shrink-0">
                        <span class="snitch-add-account-label">Platform</span>
                        <PlatformSelect
                            id="competitor-platform"
                            v-model="form.platform"
                            :platforms="platforms"
                            class="snitch-add-account-control"
                        />
                    </label>

                    <label class="block min-w-0 flex-1">
                        <span class="snitch-add-account-label">Handle</span>
                        <input
                            id="competitor-handle"
                            v-model="form.handle"
                            class="snitch-field snitch-add-account-control mt-1"
                            placeholder="@competitor"
                            autocomplete="off"
                            required
                        />
                    </label>

                    <div class="sm:shrink-0">
                        <button
                            type="submit"
                            class="snitch-btn snitch-btn-spot snitch-add-account-submit w-full sm:w-auto"
                            :disabled="form.processing || suggesting"
                        >
                            <span class="relative z-10 inline-flex items-center gap-2">
                                <LoaderCircle
                                    v-if="form.processing"
                                    class="size-3.5 shrink-0 animate-spin"
                                    aria-hidden="true"
                                />
                                <Plus
                                    v-else
                                    class="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ form.processing ? 'Adding…' : 'Add' }}
                            </span>
                        </button>
                    </div>
                </div>
            </form>

            <section v-if="localSuggestions.length" class="mt-10">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="snitch-display text-2xl text-snitch-ink">
                            Suggested rivals
                        </h2>
                        <p class="mt-1.5 text-sm text-snitch-ink/65">
                            Select accounts to track, then confirm. Reload keeps this table until you dismiss or re-run.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                            @click="toggleSelectAll"
                        >
                            {{ allSelected ? 'Clear selection' : 'Select all' }}
                        </button>
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                            @click="dismiss"
                        >
                            <X class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                            <span class="relative z-10">Dismiss</span>
                        </button>
                    </div>
                </div>

                <div class="snitch-scrap relative mt-6 p-3 pt-5 sm:p-4 sm:pt-6">
                    <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                    <div class="relative z-10 min-w-0 overflow-x-auto">
                        <table class="w-full border-collapse text-left text-sm">
                            <thead>
                                <tr class="border-b border-snitch-ink/15">
                                    <th class="w-10 px-1.5 py-2 sm:px-2">
                                        <input
                                            type="checkbox"
                                            class="size-4 accent-[var(--snitch-spot)]"
                                            :checked="allSelected"
                                            :aria-label="allSelected ? 'Clear selection' : 'Select all'"
                                            @change="toggleSelectAll"
                                        />
                                    </th>
                                    <th class="hidden w-[7.5rem] px-2 py-2 sm:table-cell">
                                        <span class="snitch-ink-label">Platform</span>
                                    </th>
                                    <th class="min-w-0 px-1.5 py-2 sm:px-2">
                                        <span class="snitch-ink-label">Account</span>
                                    </th>
                                    <th class="hidden px-2 py-2 md:table-cell md:w-[30%]">
                                        <span class="snitch-ink-label">Source</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="item in localSuggestions"
                                    :key="suggestionKey(item)"
                                    class="cursor-pointer border-b border-snitch-ink/10 last:border-0"
                                    :class="
                                        selected[suggestionKey(item)]
                                            ? 'bg-snitch-spot/15'
                                            : 'hover:bg-snitch-ink/[0.03]'
                                    "
                                    @click="toggle(item)"
                                >
                                    <td class="px-1.5 py-2.5 align-middle sm:px-2" @click.stop>
                                        <input
                                            type="checkbox"
                                            class="size-4 accent-[var(--snitch-spot)]"
                                            :checked="!!selected[suggestionKey(item)]"
                                            :aria-label="`Select ${item.display_name}`"
                                            @change="toggle(item)"
                                        />
                                    </td>
                                    <td class="hidden px-2 py-2.5 align-middle sm:table-cell">
                                        <span class="flex items-center gap-1.5">
                                            <img
                                                :src="platformIconSrc(item.platform)"
                                                alt=""
                                                class="snitch-platform-logo size-4 shrink-0"
                                                width="16"
                                                height="16"
                                            />
                                            <span class="snitch-ink-label">
                                                {{ platformLabel(item.platform) }}
                                            </span>
                                        </span>
                                    </td>
                                    <td class="min-w-0 px-1.5 py-2.5 align-middle sm:px-2">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <img
                                                v-if="item.avatar"
                                                :src="item.avatar"
                                                alt=""
                                                class="h-8 w-8 shrink-0 object-cover"
                                                style="clip-path: polygon(4% 0, 100% 3%, 96% 100%, 0 97%)"
                                            />
                                            <div
                                                v-else
                                                class="flex h-8 w-8 shrink-0 items-center justify-center bg-snitch-teal/20 text-xs font-semibold"
                                                style="clip-path: polygon(4% 0, 100% 3%, 96% 100%, 0 97%)"
                                            >
                                                {{ item.display_name.slice(0, 1) }}
                                            </div>
                                            <div class="min-w-0">
                                                <p class="snitch-display truncate text-base">
                                                    {{ item.display_name }}
                                                </p>
                                                <p class="snitch-annotation truncate text-base leading-tight">
                                                    @{{ item.handle }}
                                                </p>
                                                <p class="mt-0.5 flex items-center gap-1 sm:hidden">
                                                    <img
                                                        :src="platformIconSrc(item.platform)"
                                                        alt=""
                                                        class="snitch-platform-logo size-3.5 shrink-0"
                                                        width="14"
                                                        height="14"
                                                    />
                                                    <span class="snitch-ink-label">
                                                        {{ platformLabel(item.platform) }}
                                                    </span>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="hidden max-w-0 px-2 py-2.5 align-middle text-xs text-snitch-ink/55 md:table-cell">
                                        <span class="line-clamp-2 break-words">
                                            {{ item.source || '-' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <button
                    type="button"
                    class="snitch-btn snitch-btn-spot mt-6 w-full sm:w-auto"
                    :disabled="selectedSuggestions.length === 0 || confirmForm.processing"
                    @click="submitConfirm"
                >
                    <span class="relative z-10 inline-flex items-center gap-2">
                        <Check class="size-3.5 shrink-0" aria-hidden="true" />
                        Confirm {{ selectedSuggestions.length }} competitors
                    </span>
                </button>
            </section>

            <div
                v-if="accounts.length"
                class="snitch-scrap relative mt-8 p-3 pt-5 sm:p-4 sm:pt-6"
            >
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                <div class="relative z-10 min-w-0 overflow-x-auto">
                    <table class="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-snitch-ink/15">
                                <th class="hidden w-[7.5rem] px-2 py-2 sm:table-cell">
                                    <span class="snitch-ink-label">Platform</span>
                                </th>
                                <th class="min-w-0 px-1.5 py-2 sm:px-2">
                                    <span class="snitch-ink-label">Account</span>
                                </th>
                                <th class="w-14 px-1.5 py-2 text-right sm:px-2 sm:text-left">
                                    <span class="snitch-ink-label">Posts</span>
                                </th>
                                <th class="hidden px-2 py-2 md:table-cell md:w-[8.5rem]">
                                    <span class="snitch-ink-label">Auto sync</span>
                                </th>
                                <th class="hidden px-2 py-2 lg:table-cell lg:w-[8.5rem]">
                                    <span class="snitch-ink-label">Last synced</span>
                                </th>
                                <th class="w-auto px-1.5 py-2 text-right sm:px-2">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="account in accounts"
                                :key="account.id"
                                class="border-b border-snitch-ink/10 last:border-0"
                                :class="
                                    isAccountSyncing(account)
                                        ? 'bg-snitch-spot/10 hover:bg-snitch-ink/[0.03]'
                                        : 'hover:bg-snitch-ink/[0.03]'
                                "
                                :data-platform="account.platform"
                                :data-syncing="isAccountSyncing(account) ? 'true' : undefined"
                            >
                                <td class="hidden px-2 py-2.5 align-middle sm:table-cell">
                                    <Link
                                        :href="competitorShow.url(account.id)"
                                        class="flex items-center gap-1.5 text-inherit no-underline outline-none focus-visible:ring-2 focus-visible:ring-snitch-ink/30"
                                    >
                                        <img
                                            :src="platformIconSrc(account.platform)"
                                            :alt="`${platformLabel(account.platform)} logo`"
                                            class="snitch-platform-logo size-4 shrink-0"
                                            width="16"
                                            height="16"
                                        />
                                        <span class="snitch-ink-label">
                                            {{ platformLabel(account.platform) }}
                                        </span>
                                    </Link>
                                </td>
                                <td class="min-w-0 px-1.5 py-2.5 align-middle sm:px-2">
                                    <Link
                                        :href="competitorShow.url(account.id)"
                                        class="flex min-w-0 items-center gap-2 text-inherit no-underline outline-none focus-visible:ring-2 focus-visible:ring-snitch-ink/30"
                                    >
                                        <img
                                            v-if="account.avatar"
                                            :src="account.avatar"
                                            alt=""
                                            class="h-8 w-8 shrink-0 object-cover"
                                            style="clip-path: polygon(4% 0, 100% 3%, 96% 100%, 0 97%)"
                                        />
                                        <div
                                            v-else
                                            class="flex h-8 w-8 shrink-0 items-center justify-center bg-snitch-teal/20 text-xs font-semibold"
                                            style="clip-path: polygon(4% 0, 100% 3%, 96% 100%, 0 97%)"
                                        >
                                            {{ account.handle.slice(0, 2).toUpperCase() }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="snitch-display truncate text-base">
                                                {{ account.display_name || account.handle }}
                                            </p>
                                            <p class="snitch-annotation truncate text-base leading-tight">
                                                @{{ account.handle }}
                                            </p>
                                            <p class="mt-0.5 flex items-center gap-1 sm:hidden">
                                                <img
                                                    :src="platformIconSrc(account.platform)"
                                                    :alt="`${platformLabel(account.platform)} logo`"
                                                    class="snitch-platform-logo size-3.5 shrink-0"
                                                    width="14"
                                                    height="14"
                                                />
                                                <span class="snitch-ink-label">
                                                    {{ platformLabel(account.platform) }}
                                                </span>
                                            </p>
                                            <p
                                                v-if="isAccountSyncing(account)"
                                                class="mt-0.5 text-xs font-medium text-snitch-ink"
                                                aria-live="polite"
                                            >
                                                <span
                                                    class="mr-1 inline-block size-1.5 animate-pulse rounded-full bg-snitch-spot align-middle"
                                                    aria-hidden="true"
                                                />
                                                Sync in progress
                                            </p>
                                            <p
                                                v-else-if="emptyImportHint(account)"
                                                class="mt-0.5 text-xs font-medium text-snitch-ink/70"
                                            >
                                                {{ emptyImportHint(account) }}
                                            </p>
                                            <p
                                                v-else
                                                class="mt-0.5 text-[11px] text-snitch-ink/55 md:hidden"
                                            >
                                                Auto sync {{ accountNextSyncLabel(account) }}
                                            </p>
                                        </div>
                                    </Link>
                                </td>
                                <td class="w-14 px-1.5 py-2.5 align-middle text-right tabular-nums text-snitch-ink/70 sm:px-2 sm:text-left">
                                    {{ account.posts_count ?? 0 }}
                                </td>
                                <td class="hidden px-2 py-2.5 align-middle text-xs md:table-cell">
                                    <span
                                        class="font-medium"
                                        :class="
                                            isAccountSyncing(account)
                                                ? 'text-snitch-ink'
                                                : accountSyncDue(account)
                                                  ? 'text-snitch-ink'
                                                  : 'text-snitch-ink/55'
                                        "
                                        :aria-live="isAccountSyncing(account) ? 'polite' : undefined"
                                    >
                                        <span
                                            v-if="isAccountSyncing(account)"
                                            class="mr-1.5 inline-block size-1.5 animate-pulse rounded-full bg-snitch-spot align-middle"
                                            aria-hidden="true"
                                        />
                                        {{ accountNextSyncLabel(account) }}
                                    </span>
                                </td>
                                <td class="hidden px-2 py-2.5 align-middle text-xs text-snitch-ink/55 lg:table-cell">
                                    {{
                                        isAccountSyncing(account)
                                            ? 'In progress'
                                            : lastSyncedLabel(account.last_synced_at) || '-'
                                    }}
                                </td>
                                <td class="px-1.5 py-2.5 align-middle text-right sm:px-2">
                                    <div class="flex flex-nowrap justify-end gap-1 sm:gap-1.5">
                                        <button
                                            type="button"
                                            class="snitch-btn snitch-btn-spot shrink-0 px-2 py-1 text-xs sm:px-2.5"
                                            :disabled="isAccountSyncing(account) || !canRunBillable"
                                            :title="syncButtonTitle(account)"
                                            :aria-label="syncButtonTitle(account)"
                                            @click="syncAccount(account)"
                                        >
                                            <span class="relative z-10 inline-flex items-center gap-1.5">
                                                <LoaderCircle
                                                    v-if="isAccountSyncing(account)"
                                                    class="size-3 shrink-0 animate-spin"
                                                    aria-hidden="true"
                                                />
                                                <RefreshCw
                                                    v-else
                                                    class="size-3 shrink-0"
                                                    aria-hidden="true"
                                                />
                                                {{ isAccountSyncing(account) ? 'Syncing…' : 'Sync' }}
                                            </span>
                                        </button>
                                        <button
                                            type="button"
                                            class="snitch-btn snitch-btn-ghost shrink-0 px-2 py-1 text-xs sm:px-2.5"
                                            :aria-label="`Remove @${account.handle}`"
                                            @click="askRemove(account)"
                                        >
                                            <Trash2 class="relative z-10 size-3 shrink-0" aria-hidden="true" />
                                            <span class="relative z-10">Remove</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div
                v-else-if="!localSuggestions.length && !suggesting"
                class="snitch-scrap relative mx-auto mt-10 max-w-md p-8 text-center"
            >
                <span class="snitch-tape left-8 -top-2" aria-hidden="true" />
                <Users class="mx-auto size-8 text-snitch-ink/35" aria-hidden="true" />
                <p class="snitch-display mt-3 text-2xl">No competitors yet</p>
                <p class="mt-2 text-sm text-snitch-ink/65">
                    Add a handle above, or ask Snitch to suggest competitors.
                </p>
            </div>
        </div>

        <RemoveCompetitorModal
            v-model:open="removeDialogOpen"
            :account="accountToRemove"
        />
    </div>
</template>
