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
    batchSync,
    confirmSuggestions,
    dismissSuggestions,
    show as competitorShow,
    store,
    suggest,
    suggestStatus,
    sync,
} from '@/actions/App/Http/Controllers/CompetitorController';
import BulkActionBar from '@/components/BulkActionBar.vue';
import PlatformSelect from '@/components/PlatformSelect.vue';
import RemoveCompetitorModal from '@/components/RemoveCompetitorModal.vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import SnitchSkeleton from '@/components/SnitchSkeleton.vue';
import SuggestCompetitorsModal from '@/components/SuggestCompetitorsModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { platformIconSrc, platformLabel } from '@/lib/platforms';
import { lastSyncedLabel } from '@/lib/syncSchedule';
import { useToastStore } from '@/stores/toastStore';
import type { SubscriptionSummary } from '@/types/global';

type Account = {
    id: number;
    platform: string;
    handle: string;
    display_name: string | null;
    avatar: string | null;
    url: string;
    reels_count?: number;
    analysis_backlog_count?: number;
    winners_count?: number;
    last_synced_at: string | null;
    last_sync_status?: string | null;
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
    accounts?: Account[] | null;
    platforms: string[];
    suggestPlatforms?: string[];
    competitorBrief?: string;
    suggestions?: Suggestion[] | null;
    suggestRun?: SuggestRun | null;
    suggestError?: string | null;
    competitorCap?: CompetitorCap | null;
}>();

defineOptions({
    layout: AppLayout,
});

const toast = useToastStore();

const accountsList = computed<Account[]>(() => props.accounts ?? []);
const accountsLoaded = computed(() => Array.isArray(props.accounts));

const competitorsUsed = computed(() => {
    if (props.competitorCap?.competitors_used != null) {
        return props.competitorCap.competitors_used;
    }

    return accountsList.value.length;
});

const selected = ref<Record<string, boolean>>({});
const selectedAccountIds = ref<Record<number, boolean>>({});
const localSuggestions = ref<Suggestion[]>([]);
const suggesting = ref(false);
const suggestMessage = ref(props.suggestError ?? '');
const removeDialogOpen = ref(false);
const suggestModalOpen = ref(false);
const accountToRemove = ref<Account | null>(null);
const accountsToRemove = ref<Account[]>([]);
const batchWorking = ref(false);
const syncingIds = ref<Record<number, boolean>>({});
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let syncPollTimer: ReturnType<typeof setInterval> | null = null;

const suggestPlatformOptions = computed(() =>
    props.suggestPlatforms && props.suggestPlatforms.length > 0
        ? props.suggestPlatforms
        : props.platforms,
);

const hasRunningSync = computed(() =>
    accountsList.value.some(
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

    for (const account of accountsList.value) {
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
        syncSuggestionsFromProps(rows ?? []);
    },
    { immediate: true, deep: true },
);

watch(
    () => props.accounts,
    (accounts) => {
        syncSuggestionsFromProps(localSuggestions.value);

        const nextSelected: Record<number, boolean> = {};

        for (const account of accounts ?? []) {
            if (selectedAccountIds.value[account.id]) {
                nextSelected[account.id] = true;
            }
        }

        selectedAccountIds.value = nextSelected;
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

const allSuggestionsSelected = computed(
    () =>
        localSuggestions.value.length > 0 &&
        localSuggestions.value.every((item) => selected.value[`${item.platform}:${item.handle}`]),
);

const selectedAccounts = computed(() =>
    accountsList.value.filter((account) => !!selectedAccountIds.value[account.id]),
);

const allAccountsSelected = computed(
    () =>
        accountsList.value.length > 0 &&
        accountsList.value.every((account) => !!selectedAccountIds.value[account.id]),
);

const showSuggestActionBar = computed(() => selectedSuggestions.value.length > 0);
const showAccountActionBar = computed(() => selectedAccounts.value.length > 0);
const showAnyActionBar = computed(
    () => showSuggestActionBar.value || showAccountActionBar.value,
);

function suggestionKey(item: Suggestion): string {
    return `${item.platform}:${item.handle}`;
}

function toggle(item: Suggestion): void {
    const key = suggestionKey(item);
    selected.value[key] = !selected.value[key];
}

function selectAllSuggestions(): void {
    const next: Record<string, boolean> = {};

    for (const item of localSuggestions.value) {
        next[suggestionKey(item)] = true;
    }

    selected.value = next;
}

function clearSuggestionSelection(): void {
    selected.value = {};
}

function toggleSelectAllSuggestions(): void {
    if (allSuggestionsSelected.value) {
        clearSuggestionSelection();
    } else {
        selectAllSuggestions();
    }
}

function toggleAccount(account: Account): void {
    selectedAccountIds.value = {
        ...selectedAccountIds.value,
        [account.id]: !selectedAccountIds.value[account.id],
    };
}

function selectAllAccounts(): void {
    const next: Record<number, boolean> = {};

    for (const account of accountsList.value) {
        next[account.id] = true;
    }

    selectedAccountIds.value = next;
}

function clearAccountSelection(): void {
    selectedAccountIds.value = {};
}

function toggleSelectAllAccounts(): void {
    if (allAccountsSelected.value) {
        clearAccountSelection();
    } else {
        selectAllAccounts();
    }
}

function clearAllSelections(): void {
    clearSuggestionSelection();
    clearAccountSelection();
}

function onSelectionKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && showAnyActionBar.value) {
        clearAllSelections();
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
    window.addEventListener('keydown', onSelectionKeydown);

    if (localSuggestions.value.length > 0 && Object.keys(selected.value).length === 0) {
        selectAllSuggestions();
        suggestMessage.value =
            suggestMessage.value || `Found ${localSuggestions.value.length} snitches.`;
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
    window.removeEventListener('keydown', onSelectionKeydown);
    clearAllSelections();
    clearPoll();
    clearSyncPoll();
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
                ? `Found ${localSuggestions.value.length} snitches.`
                : 'No verified snitches found.';

        if (localSuggestions.value.length === 0) {
            toast.error('No verified snitch accounts found. Try again later.');
        } else {
            selectAllSuggestions();
            toast.success('Snitch picks ready.');
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
        toast.error(payload.error || 'Could not suggest snitches.');

        if (localSuggestions.value.length > 0) {
            selectAllSuggestions();
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

function openSuggestModal(): void {
    if (suggesting.value) {
        return;
    }

    suggestModalOpen.value = true;
}

async function requestSuggestions(filters: {
    platforms: string[];
    brief: string;
}): Promise<void> {
    if (suggesting.value) {
        return;
    }

    clearPoll();
    suggesting.value = true;
    suggestModalOpen.value = false;
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
            body: JSON.stringify({
                platforms: filters.platforms,
                brief: filters.brief,
            }),
        });

        if (!response.ok) {
            const errorBody = (await response.json().catch(() => null)) as
                | { message?: string; errors?: Record<string, string[]> }
                | null;

            const firstError = errorBody?.errors
                ? Object.values(errorBody.errors).flat()[0]
                : undefined;

            throw new Error(
                firstError || errorBody?.message || 'Could not start snitch suggestions.',
            );
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

function dismissSelectedSuggestions(): void {
    const dismissed = selectedSuggestions.value;

    if (dismissed.length === 0) {
        return;
    }

    const dismissedKeys = new Set(dismissed.map((item) => suggestionKey(item).toLowerCase()));

    router.post(
        dismissSuggestions.url(),
        {
            suggestions: dismissed.map((item) => ({
                platform: item.platform,
                handle: item.handle,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                localSuggestions.value = localSuggestions.value.filter(
                    (item) => !dismissedKeys.has(suggestionKey(item).toLowerCase()),
                );
                clearSuggestionSelection();
                suggestMessage.value =
                    localSuggestions.value.length > 0
                        ? `${localSuggestions.value.length} suggestions left.`
                        : '';
            },
        },
    );
}

function askRemove(account: Account): void {
    accountToRemove.value = account;
    accountsToRemove.value = [];
    removeDialogOpen.value = true;
}

function askRemoveSelectedAccounts(): void {
    if (selectedAccounts.value.length === 0) {
        return;
    }

    accountToRemove.value = null;
    accountsToRemove.value = [...selectedAccounts.value];
    removeDialogOpen.value = true;
}

function onAccountsRemoved(): void {
    clearAccountSelection();
}

function syncSelectedAccounts(): void {
    if (batchWorking.value || !canRunBillable.value || selectedAccounts.value.length === 0) {
        return;
    }

    const ids = selectedAccounts.value
        .filter((account) => !isAccountSyncing(account))
        .map((account) => account.id);

    if (ids.length === 0) {
        return;
    }

    batchWorking.value = true;
    const nextSyncing = { ...syncingIds.value };

    for (const id of ids) {
        nextSyncing[id] = true;
    }

    syncingIds.value = nextSyncing;

    router.post(
        batchSync.url(),
        { ids },
        {
            preserveScroll: true,
            onFinish: () => {
                batchWorking.value = false;
                const cleared = { ...syncingIds.value };

                for (const id of ids) {
                    delete cleared[id];
                }

                syncingIds.value = cleared;
            },
            onSuccess: () => {
                clearAccountSelection();
            },
        },
    );
}

function accountSyncStatusLabel(account: Account): string {
    if (isAccountSyncing(account)) {
        return 'Syncing…';
    }

    if (account.last_synced_at) {
        return lastSyncedLabel(account.last_synced_at) ?? 'Manual';
    }

    return 'Manual';
}

function isAccountSyncing(account: Account): boolean {
    return !!syncingIds.value[account.id] || account.last_sync_status === 'running';
}

function emptyImportHint(account: Account): string | null {
    if ((account.reels_count ?? 0) > 0) {
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

const syncSelectedTitle = computed(() => {
    if (!canRunBillable.value) {
        return `Balance must be more than ${minRunBalancePence.value}p to sync. Subscribe or top up on Billing.`;
    }

    return `Sync ${selectedAccounts.value.length} selected`;
});
</script>

<template>
    <div
        class="snitch-app-shell relative min-h-full min-w-0 px-5 py-6 sm:px-8 sm:py-8"
        :class="showAnyActionBar ? 'pb-28 sm:pb-32' : ''"
    >
        <Head title="Snitches" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto w-full min-w-0 max-w-6xl">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                        Snitches
                    </h1>
                    <p class="mt-1.5 text-sm text-snitch-ink/65 sm:text-base">
                        Rivals or accounts whose style you want to copy - across Instagram, TikTok, YouTube Shorts, Facebook, and LinkedIn.
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
                        @click="openSuggestModal"
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
                            {{ suggesting ? 'Finding…' : 'Suggest snitches' }}
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
                            Searching the web for accounts to track, then verifying real public profiles.
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
                            placeholder="@handle"
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

            <section v-if="localSuggestions.length" class="mt-10" aria-labelledby="suggested-snitches-heading">
                <div>
                    <h2 id="suggested-snitches-heading" class="snitch-display text-2xl text-snitch-ink">
                        Suggested snitches
                    </h2>
                    <p class="mt-1.5 text-sm text-snitch-ink/65">
                        Pending suggestions (including from an agent) are not tracked yet. Select rows to confirm or dismiss. Reload keeps this table until you clear it or re-run.
                    </p>
                </div>

                <div class="snitch-scrap relative mt-6 p-3 pt-5 pb-6 sm:p-4 sm:pt-6 sm:pb-7">
                    <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                    <div class="relative z-10 min-w-0 overflow-x-auto">
                        <table class="w-full border-collapse text-left text-sm">
                            <thead>
                                <tr class="border-b border-snitch-ink/15">
                                    <th class="w-10 px-1.5 py-2 sm:px-2">
                                        <input
                                            type="checkbox"
                                            class="size-4 accent-[var(--snitch-spot)]"
                                            :checked="allSuggestionsSelected"
                                            :aria-label="allSuggestionsSelected ? 'Clear suggested selection' : 'Select all suggested snitches'"
                                            @change="toggleSelectAllSuggestions"
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
                                            <SnitchAvatar
                                                :src="item.avatar"
                                                :name="item.display_name"
                                                :handle="item.handle"
                                                size="sm"
                                            />
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
            </section>

            <div
                v-if="!accountsLoaded"
                class="snitch-scrap relative mt-8 space-y-3 p-3 pt-5 pb-6 sm:p-4 sm:pt-6 sm:pb-7"
                aria-live="polite"
                aria-label="Loading tracked snitches"
            >
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                <div class="relative z-10 space-y-2.5">
                    <SnitchSkeleton
                        v-for="row in 4"
                        :key="row"
                        variant="scrap"
                        height="3rem"
                    />
                </div>
            </div>

            <div
                v-else-if="accountsList.length"
                class="snitch-scrap relative mt-8 p-3 pt-5 pb-6 sm:p-4 sm:pt-6 sm:pb-7"
            >
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                <div class="relative z-10 min-w-0 overflow-x-auto">
                    <table class="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-snitch-ink/15">
                                <th class="w-10 px-1.5 py-2 sm:px-2">
                                    <input
                                        type="checkbox"
                                        class="size-4 accent-[var(--snitch-spot)]"
                                        :checked="allAccountsSelected"
                                        :aria-label="allAccountsSelected ? 'Clear tracked selection' : 'Select all tracked snitches'"
                                        @change="toggleSelectAllAccounts"
                                    />
                                </th>
                                <th class="hidden w-[7.5rem] px-2 py-2 sm:table-cell">
                                    <span class="snitch-ink-label">Platform</span>
                                </th>
                                <th class="min-w-0 px-1.5 py-2 sm:px-2">
                                    <span class="snitch-ink-label">Account</span>
                                </th>
                                <th class="w-14 px-1.5 py-2 text-right sm:px-2 sm:text-left">
                                    <span class="snitch-ink-label">Reels</span>
                                </th>
                                <th class="hidden w-16 px-2 py-2 text-right sm:table-cell sm:text-left">
                                    <span class="snitch-ink-label">Backlog</span>
                                </th>
                                <th class="hidden w-16 px-2 py-2 text-right md:table-cell md:text-left">
                                    <span class="snitch-ink-label">Winners</span>
                                </th>
                                <th class="hidden px-2 py-2 lg:table-cell lg:w-[8.5rem]">
                                    <span class="snitch-ink-label">Sync status</span>
                                </th>
                                <th class="w-auto px-1.5 py-2 text-right sm:px-2">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="account in accountsList"
                                :key="account.id"
                                class="border-b border-snitch-ink/10 last:border-0"
                                :class="[
                                    selectedAccountIds[account.id]
                                        ? 'bg-snitch-spot/15'
                                        : isAccountSyncing(account)
                                          ? 'bg-snitch-spot/10'
                                          : '',
                                    'hover:bg-snitch-ink/[0.03]',
                                ]"
                                :data-platform="account.platform"
                                :data-syncing="isAccountSyncing(account) ? 'true' : undefined"
                            >
                                <td class="px-1.5 py-2.5 align-middle sm:px-2" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="size-4 accent-[var(--snitch-spot)]"
                                        :checked="!!selectedAccountIds[account.id]"
                                        :aria-label="`Select @${account.handle}`"
                                        @change="toggleAccount(account)"
                                    />
                                </td>
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
                                        <SnitchAvatar
                                            :src="account.avatar"
                                            :name="account.display_name"
                                            :handle="account.handle"
                                            size="sm"
                                        />
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
                                                class="mt-0.5 text-[11px] text-snitch-ink/55 lg:hidden"
                                            >
                                                Sync {{ accountSyncStatusLabel(account) }}
                                            </p>
                                        </div>
                                    </Link>
                                </td>
                                <td class="w-14 px-1.5 py-2.5 align-middle text-right tabular-nums text-snitch-ink/70 sm:px-2 sm:text-left">
                                    {{ account.reels_count ?? 0 }}
                                </td>
                                <td class="hidden px-2 py-2.5 align-middle text-right tabular-nums text-snitch-ink/70 sm:table-cell sm:text-left">
                                    {{ account.analysis_backlog_count ?? 0 }}
                                </td>
                                <td class="hidden px-2 py-2.5 align-middle text-right tabular-nums text-snitch-ink/70 md:table-cell md:text-left">
                                    {{ account.winners_count ?? 0 }}
                                </td>
                                <td class="hidden px-2 py-2.5 align-middle text-xs lg:table-cell">
                                    <span
                                        class="font-medium"
                                        :class="
                                            isAccountSyncing(account)
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
                                        {{ accountSyncStatusLabel(account) }}
                                    </span>
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
                v-else-if="accountsLoaded && !localSuggestions.length && !suggesting"
                class="snitch-scrap relative mx-auto mt-10 max-w-md p-8 text-center"
            >
                <span class="snitch-tape left-8 -top-2" aria-hidden="true" />
                <Users class="mx-auto size-8 text-snitch-ink/35" aria-hidden="true" />
                <p class="snitch-display mt-3 text-2xl">No snitches yet</p>
                <p class="mt-2 text-sm text-snitch-ink/65">
                    Add a handle above, or ask Snitch to suggest accounts to track.
                </p>
            </div>
        </div>

        <div
            v-if="showAnyActionBar"
            class="pointer-events-none fixed inset-x-0 bottom-0 z-40 flex flex-col items-center gap-2.5 px-4 pb-4 sm:pb-5"
        >
            <BulkActionBar
                v-if="showSuggestActionBar"
                :count="selectedSuggestions.length"
                :label="selectedSuggestions.length === 1 ? 'suggested snitch' : 'suggested snitches'"
                aria-label="Suggested snitches actions"
            >
                <button
                    type="button"
                    class="snitch-btn snitch-btn-ghost px-2.5 py-1.5 text-xs sm:text-sm"
                    @click="toggleSelectAllSuggestions"
                >
                    {{ allSuggestionsSelected ? 'Clear' : 'Select all' }}
                </button>
                <button
                    type="button"
                    class="snitch-btn snitch-btn-ghost px-2.5 py-1.5 text-xs sm:text-sm"
                    :disabled="confirmForm.processing"
                    @click="dismissSelectedSuggestions"
                >
                    <X class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                    <span class="relative z-10">Dismiss</span>
                </button>
                <button
                    type="button"
                    class="snitch-btn snitch-btn-spot px-2.5 py-1.5 text-xs sm:text-sm"
                    :disabled="confirmForm.processing"
                    @click="submitConfirm"
                >
                    <span class="relative z-10 inline-flex items-center gap-1.5">
                        <LoaderCircle
                            v-if="confirmForm.processing"
                            class="size-3.5 shrink-0 animate-spin"
                            aria-hidden="true"
                        />
                        <Check
                            v-else
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        Confirm {{ selectedSuggestions.length }}
                    </span>
                </button>
            </BulkActionBar>

            <BulkActionBar
                v-if="showAccountActionBar"
                :count="selectedAccounts.length"
                :label="selectedAccounts.length === 1 ? 'tracked snitch' : 'tracked snitches'"
                aria-label="Tracked snitches actions"
            >
                <button
                    type="button"
                    class="snitch-btn snitch-btn-ghost px-2.5 py-1.5 text-xs sm:text-sm"
                    @click="toggleSelectAllAccounts"
                >
                    {{ allAccountsSelected ? 'Clear' : 'Select all' }}
                </button>
                <button
                    type="button"
                    class="snitch-btn snitch-btn-ghost px-2.5 py-1.5 text-xs sm:text-sm"
                    :disabled="batchWorking"
                    @click="askRemoveSelectedAccounts"
                >
                    <Trash2 class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                    <span class="relative z-10">Remove</span>
                </button>
                <button
                    type="button"
                    class="snitch-btn snitch-btn-spot px-2.5 py-1.5 text-xs sm:text-sm"
                    :disabled="batchWorking || !canRunBillable"
                    :title="syncSelectedTitle"
                    :aria-label="syncSelectedTitle"
                    @click="syncSelectedAccounts"
                >
                    <span class="relative z-10 inline-flex items-center gap-1.5">
                        <LoaderCircle
                            v-if="batchWorking"
                            class="size-3.5 shrink-0 animate-spin"
                            aria-hidden="true"
                        />
                        <RefreshCw
                            v-else
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        Sync {{ selectedAccounts.length }}
                    </span>
                </button>
            </BulkActionBar>
        </div>

        <RemoveCompetitorModal
            v-model:open="removeDialogOpen"
            :account="accountToRemove"
            :accounts="accountsToRemove"
            @removed="onAccountsRemoved"
        />

        <SuggestCompetitorsModal
            v-model:open="suggestModalOpen"
            :platforms="suggestPlatformOptions"
            :brief="competitorBrief ?? ''"
            :busy="suggesting"
            @submit="requestSuggestions"
        />
    </div>
</template>
