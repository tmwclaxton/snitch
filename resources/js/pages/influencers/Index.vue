<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    ExternalLink,
    LoaderCircle,
    Search,
    Sparkles,
    Trash2,
    UserRoundSearch,
    X,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import {
    discard,
    generateBrief,
    keep,
    search,
    searchStatus,
} from '@/actions/App/Http/Controllers/InfluencerController';
import PaperSelect from '@/components/PaperSelect.vue';
import RemoveCompetitorModal from '@/components/RemoveCompetitorModal.vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { platformIconSrc, platformLabel } from '@/lib/platforms';
import { useToastStore } from '@/stores/toastStore';

type Suggestion = {
    platform: string;
    handle: string;
    url: string;
    display_name: string;
    avatar: string | null;
    source?: string | null;
    followers?: number | null;
    language_hint?: string | null;
    fit_reason?: string | null;
};

type KeptAccount = {
    id: number;
    platform: string;
    handle: string;
    display_name: string | null;
    avatar: string | null;
    url: string;
    fit_reason?: string | null;
    posts_count?: number;
};

type SearchRun = {
    id: string;
    status: 'pending' | 'processing';
};

type LatestRun = {
    id: string | null;
    status: string | null;
    brief: string;
    error: string | null;
    review_complete: boolean;
};

type InfluencerCap = {
    plan?: string;
    plan_name?: string;
    influencer_limit?: number | null;
    influencers_used?: number;
    influencers_remaining?: number | null;
    can_upgrade?: boolean;
};

type Filters = {
    platform: string;
    language: string | null;
    min_followers: number | null;
    max_followers: number | null;
    brief: string;
};

const DEFAULT_MIN_FOLLOWERS = 1000;
const DEFAULT_MAX_FOLLOWERS = 50000;

const props = defineProps<{
    brand: { name: string; description: string | null } | null;
    platforms: string[];
    filters: Filters;
    searchRun?: SearchRun | null;
    latestRun?: LatestRun | null;
    suggestions: Suggestion[];
    decisions: Record<string, 'kept' | 'discarded'>;
    reviewQueue: Suggestion[];
    keptAccounts: KeptAccount[];
    canSearch: boolean;
    influencerCap?: InfluencerCap | null;
}>();

defineOptions({
    layout: AppLayout,
});

const toast = useToastStore();

const languageOptions = [
    { value: 'English', label: 'English' },
    { value: 'Spanish', label: 'Spanish' },
    { value: 'French', label: 'French' },
    { value: 'German', label: 'German' },
    { value: 'any', label: 'Any' },
];

function normalizeLanguage(value: string | null | undefined): string {
    const raw = (value ?? '').trim().toLowerCase();

    if (raw === '' || raw === 'en' || raw === 'eng' || raw === 'english' || raw === 'en-gb' || raw === 'en-us' || raw === 'en_gb' || raw === 'en_us') {
        return 'English';
    }

    if (raw === 'es' || raw === 'spa' || raw === 'spanish' || raw === 'es-es' || raw === 'es_es') {
        return 'Spanish';
    }

    if (raw === 'fr' || raw === 'fre' || raw === 'fra' || raw === 'french' || raw === 'fr-fr' || raw === 'fr_fr') {
        return 'French';
    }

    if (raw === 'de' || raw === 'ger' || raw === 'deu' || raw === 'german' || raw === 'de-de' || raw === 'de_de') {
        return 'German';
    }

    if (raw === 'any') {
        return 'any';
    }

    const match = languageOptions.find((option) => option.value.toLowerCase() === raw);

    return match?.value ?? 'English';
}

const form = useForm({
    platform: props.filters.platform || props.platforms[0] || 'instagram',
    language: normalizeLanguage(props.filters.language),
    min_followers: props.filters.min_followers ?? DEFAULT_MIN_FOLLOWERS,
    max_followers: props.filters.max_followers ?? DEFAULT_MAX_FOLLOWERS,
    brief: props.filters.brief ?? '',
});

const localSuggestions = ref<Suggestion[]>([...props.suggestions]);
const localDecisions = ref<Record<string, 'kept' | 'discarded'>>({ ...props.decisions });
const searching = ref(!!props.searchRun);
const searchMessage = ref(props.latestRun?.error ?? '');
const generatingBrief = ref(false);
const decidingKey = ref<string | null>(null);
const removeDialogOpen = ref(false);
const accountToRemove = ref<KeptAccount | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const platformOptions = computed(() =>
    props.platforms.map((platform) => ({
        value: platform,
        label: platformLabel(platform),
        iconSrc: platformIconSrc(platform),
    })),
);

const pendingReview = computed(() =>
    localSuggestions.value.filter((item) => !localDecisions.value[suggestionKey(item)]),
);

const reviewedCount = computed(() => {
    return localSuggestions.value.filter((item) => !!localDecisions.value[suggestionKey(item)]).length;
});

const totalCount = computed(() => localSuggestions.value.length);

const searchLocked = computed(() => searching.value || !props.canSearch);

const thinFailedHint = computed(() => {
    const status = props.latestRun?.status;
    const min = 6;

    return (
        status === 'failed' &&
        localSuggestions.value.length > 0 &&
        localSuggestions.value.length < min &&
        props.canSearch &&
        !searching.value
    );
});

function suggestionKey(item: Suggestion): string {
    return `${item.platform}:${item.handle}`;
}

function formatFollowers(value: number | null | undefined): string {
    if (value == null) {
        return 'unknown';
    }

    if (value >= 1_000_000) {
        return `${(value / 1_000_000).toFixed(1)}M`;
    }

    if (value >= 1_000) {
        return `${(value / 1_000).toFixed(1)}k`;
    }

    return String(value);
}

async function onGenerateBrief(): Promise<void> {
    if (generatingBrief.value || searching.value) {
        return;
    }

    generatingBrief.value = true;

    try {
        const response = await fetch(generateBrief.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN':
                    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ??
                    '',
            },
            body: JSON.stringify({
                platform: form.platform,
                language: form.language,
                min_followers: form.min_followers,
                max_followers: form.max_followers,
            }),
        });

        const data = (await response.json()) as { brief?: string; message?: string };

        if (!response.ok) {
            toast.error(data.message || 'Could not generate brief.');

            return;
        }

        form.brief = data.brief ?? '';
    } catch {
        toast.error('Could not generate brief.');
    } finally {
        generatingBrief.value = false;
    }
}

async function kickOffSearch(): Promise<void> {
    if (searchLocked.value || form.brief.trim().length < 8) {
        return;
    }

    searching.value = true;
    searchMessage.value = '';
    localSuggestions.value = [];
    localDecisions.value = {};

    try {
        const response = await fetch(search.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN':
                    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ??
                    '',
            },
            body: JSON.stringify({
                platform: form.platform,
                language: form.language === 'any' ? null : form.language,
                min_followers: form.min_followers,
                max_followers: form.max_followers,
                brief: form.brief,
            }),
        });

        const data = (await response.json()) as {
            id?: string;
            status?: string;
            message?: string;
        };

        if (!response.ok || !data.id) {
            searching.value = false;
            toast.error(data.message || 'Could not start influencer search.');

            return;
        }

        pollStatus(data.id);
    } catch {
        searching.value = false;
        toast.error('Could not start influencer search.');
    }
}

async function pollStatus(runId: string): Promise<void> {
    clearPoll();

    try {
        const response = await fetch(searchStatus.url(runId), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const data = (await response.json()) as {
            id: string;
            status: string;
            suggestions?: Suggestion[] | null;
            decisions?: Record<string, 'kept' | 'discarded'> | null;
            error?: string | null;
        };

        if (Array.isArray(data.suggestions)) {
            localSuggestions.value = data.suggestions;
        }

        if (data.decisions) {
            localDecisions.value = data.decisions;
        }

        if (data.status === 'processing' || data.status === 'pending') {
            pollTimer = setTimeout(() => {
                void pollStatus(runId);
            }, 2000);

            return;
        }

        searching.value = false;
        searchMessage.value = data.error ?? '';

        if (data.status === 'failed' && data.error) {
            toast.error(data.error);
        }

        router.reload({ only: ['searchRun', 'latestRun', 'suggestions', 'decisions', 'reviewQueue', 'keptAccounts', 'canSearch', 'filters', 'influencerCap'] });
    } catch {
        pollTimer = setTimeout(() => {
            void pollStatus(runId);
        }, 3000);
    }
}

function clearPoll(): void {
    if (pollTimer) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}

function onKeep(item: Suggestion): void {
    const key = suggestionKey(item);
    decidingKey.value = key;

    router.post(
        keep.url(),
        {
            platform: item.platform,
            handle: item.handle,
            run_id: props.latestRun?.id ?? props.searchRun?.id ?? null,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                decidingKey.value = null;
            },
        },
    );
}

function onDiscard(item: Suggestion): void {
    const key = suggestionKey(item);
    decidingKey.value = key;

    router.post(
        discard.url(),
        {
            platform: item.platform,
            handle: item.handle,
            run_id: props.latestRun?.id ?? props.searchRun?.id ?? null,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                decidingKey.value = null;
            },
        },
    );
}

function openRemove(account: KeptAccount): void {
    accountToRemove.value = account;
    removeDialogOpen.value = true;
}

watch(
    () => props.suggestions,
    (rows) => {
        localSuggestions.value = [...rows];
    },
    { deep: true },
);

watch(
    () => props.decisions,
    (rows) => {
        localDecisions.value = { ...rows };
    },
    { deep: true },
);

onMounted(() => {
    if (props.searchRun?.id) {
        searching.value = true;
        pollStatus(props.searchRun.id);
    }
});

onUnmounted(() => {
    clearPoll();
});
</script>

<template>
    <div class="snitch-app-shell relative min-h-full min-w-0 px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Find Influencers" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto w-full min-w-0 max-w-6xl">
            <header>
                <p class="snitch-ink-label">Brand deals</p>
                <h1 class="snitch-display mt-2 text-3xl text-snitch-ink sm:text-4xl">
                    Find influencers
                </h1>
                <p v-if="brand?.name" class="snitch-annotation mt-2 text-xl text-snitch-ink/80">
                    For {{ brand.name }}
                </p>
                <p class="mt-2 text-sm text-snitch-ink/70">Find creators in your space to make brand deals with - ones who will remake or post about your brand on your behalf. Brief the niche, then Keep or Discard each suggestion.</p>
            </header>

            <section class="snitch-scrap relative mt-8 p-4 pt-6 sm:p-6 sm:pt-8">
                <span class="snitch-tape left-6 -top-2" aria-hidden="true" />

                <div class="relative z-10 space-y-5">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="block text-sm sm:col-span-1">
                            <span class="snitch-ink-label">Platform</span>
                            <div class="mt-1.5">
                                <PaperSelect
                                    v-model="form.platform"
                                    :options="platformOptions"
                                    ariaLabel="Platform"
                                />
                            </div>
                        </label>
                        <label class="block text-sm">
                            <span class="snitch-ink-label">Language</span>
                            <select
                                v-model="form.language"
                                class="mt-1.5 w-full border border-snitch-ink/20 bg-snitch-paper/80 px-3 py-2 text-snitch-ink"
                            >
                                <option
                                    v-for="option in languageOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="snitch-ink-label">Min followers</span>
                            <input
                                v-model.number="form.min_followers"
                                type="number"
                                min="0"
                                class="mt-1.5 w-full border border-snitch-ink/20 bg-snitch-paper/80 px-3 py-2 text-snitch-ink"
                            />
                        </label>
                        <label class="block text-sm">
                            <span class="snitch-ink-label">Max followers</span>
                            <input
                                v-model.number="form.max_followers"
                                type="number"
                                min="0"
                                class="mt-1.5 w-full border border-snitch-ink/20 bg-snitch-paper/80 px-3 py-2 text-snitch-ink"
                            />
                        </label>
                    </div>

                    <label class="block text-sm">
                        <span class="snitch-ink-label">Brief</span>
                        <textarea
                            v-model="form.brief"
                            rows="4"
                            placeholder="Auto-filled after onboarding when possible. Generate from your brand, or describe creators you want for partnerships."
                            class="mt-1.5 w-full border border-snitch-ink/20 bg-snitch-paper/80 px-3 py-2 text-snitch-ink"
                        />
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost"
                            :disabled="generatingBrief || searching"
                            @click="onGenerateBrief"
                        >
                            <span class="relative z-10 inline-flex items-center gap-2">
                                <LoaderCircle
                                    v-if="generatingBrief"
                                    class="size-3.5 animate-spin"
                                    aria-hidden="true"
                                />
                                <Sparkles v-else class="size-3.5" aria-hidden="true" />
                                {{ generatingBrief ? 'Generating…' : 'Generate' }}
                            </span>
                        </button>
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-spot"
                            :disabled="searchLocked || form.brief.trim().length < 8"
                            @click="kickOffSearch"
                        >
                            <span class="relative z-10 inline-flex items-center gap-2">
                                <LoaderCircle
                                    v-if="searching"
                                    class="size-3.5 animate-spin"
                                    aria-hidden="true"
                                />
                                <Search v-else class="size-3.5" aria-hidden="true" />
                                {{ searching ? 'Searching…' : 'Kick off search' }}
                            </span>
                        </button>
                    </div>

                    <p v-if="thinFailedHint" class="text-xs text-snitch-ink/55">
                        Last search found fewer than 6 - you can keep reviewing or search again.
                    </p>
                    <p v-else-if="searchLocked && !searching" class="text-xs text-snitch-ink/55">
                        Finish Keep / Discard on every suggestion before starting another search.
                    </p>
                    <p v-if="searchMessage" class="text-sm text-snitch-ink/70">
                        {{ searchMessage }}
                    </p>
                </div>
            </section>

            <section v-if="localSuggestions.length || searching" class="mt-10">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="snitch-display text-2xl text-snitch-ink">Review queue</h2>
                        <p class="mt-1.5 text-sm text-snitch-ink/65">
                            Reviewed {{ reviewedCount }} / {{ totalCount || '…' }}
                        </p>
                    </div>
                    <UserRoundSearch class="size-6 text-snitch-ink/40" aria-hidden="true" />
                </div>

                <div v-if="searching && !localSuggestions.length" class="snitch-scrap relative mt-6 p-6">
                    <p class="text-sm text-snitch-ink/70">Searching for creators…</p>
                </div>

                <ul class="mt-6 space-y-3">
                    <li
                        v-for="item in pendingReview"
                        :key="suggestionKey(item)"
                        class="snitch-cutout flex flex-col gap-3 bg-snitch-paper/70 px-5 py-3.5 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <SnitchAvatar
                                :src="item.avatar"
                                :name="item.display_name"
                                :handle="item.handle"
                                size="lg"
                            />
                            <div class="min-w-0">
                                <p class="snitch-display truncate text-lg">{{ item.display_name }}</p>
                                <p class="snitch-annotation truncate text-base leading-tight">
                                    @{{ item.handle }}
                                </p>
                                <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-snitch-ink/60">
                                    <span class="inline-flex items-center gap-1">
                                        <img
                                            :src="platformIconSrc(item.platform)"
                                            alt=""
                                            class="snitch-platform-logo size-3.5"
                                            width="14"
                                            height="14"
                                        />
                                        {{ platformLabel(item.platform) }}
                                    </span>
                                    <span>{{ formatFollowers(item.followers) }} followers</span>
                                </p>
                                <p
                                    v-if="item.fit_reason"
                                    class="mt-2 text-sm leading-snug text-snitch-ink/75"
                                >
                                    {{ item.fit_reason }}
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <a
                                v-if="item.url"
                                :href="item.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                            >
                                <span class="relative z-10 inline-flex items-center gap-1.5">
                                    <ExternalLink class="size-3.5" aria-hidden="true" />
                                    Profile
                                </span>
                            </a>
                            <button
                                type="button"
                                class="snitch-btn snitch-btn-spot px-3 py-1.5 text-sm"
                                :disabled="decidingKey === suggestionKey(item)"
                                @click="onKeep(item)"
                            >
                                <span class="relative z-10 inline-flex items-center gap-1.5">
                                    <Check class="size-3.5" aria-hidden="true" />
                                    Keep
                                </span>
                            </button>
                            <button
                                type="button"
                                class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                                :disabled="decidingKey === suggestionKey(item)"
                                @click="onDiscard(item)"
                            >
                                <span class="relative z-10 inline-flex items-center gap-1.5">
                                    <X class="size-3.5" aria-hidden="true" />
                                    Discard
                                </span>
                            </button>
                        </div>
                    </li>
                </ul>

                <p
                    v-if="!pendingReview.length && localSuggestions.length && !searching"
                    class="mt-4 text-sm text-snitch-ink/65"
                >
                    All suggestions decided. You can kick off a new search.
                </p>
            </section>

            <section v-if="keptAccounts.length" class="mt-12">
                <h2 class="snitch-display text-2xl text-snitch-ink">Kept influencers</h2>
                <p class="mt-1.5 text-sm text-snitch-ink/65">
                    Creators you kept for brand deals and outreach. Separate from competitor tracking.
                </p>

                <ul class="mt-6 space-y-3">
                    <li
                        v-for="account in keptAccounts"
                        :key="account.id"
                        class="snitch-cutout flex flex-col gap-3 bg-snitch-paper/70 px-5 py-3.5 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <Link
                                :href="competitorShow(account.id)"
                                class="flex min-w-0 items-start gap-3"
                            >
                                <SnitchAvatar
                                    :src="account.avatar"
                                    :name="account.display_name"
                                    :handle="account.handle"
                                    size="md"
                                />
                                <div class="min-w-0">
                                    <p class="snitch-display truncate text-base">
                                        {{ account.display_name || account.handle }}
                                    </p>
                                    <p class="text-xs text-snitch-ink/60">
                                        {{ platformLabel(account.platform) }} · @{{ account.handle }}
                                    </p>
                                    <p
                                        v-if="account.fit_reason"
                                        class="mt-2 text-sm leading-snug text-snitch-ink/75"
                                    >
                                        {{ account.fit_reason }}
                                    </p>
                                </div>
                            </Link>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <a
                                v-if="account.url"
                                :href="account.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                            >
                                <span class="relative z-10 inline-flex items-center gap-1.5">
                                    <ExternalLink class="size-3.5" aria-hidden="true" />
                                    Profile
                                </span>
                            </a>
                            <button
                                type="button"
                                class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                                @click="openRemove(account)"
                            >
                                <span class="relative z-10 inline-flex items-center gap-1.5">
                                    <Trash2 class="size-3.5" aria-hidden="true" />
                                    Remove
                                </span>
                            </button>
                        </div>
                    </li>
                </ul>
            </section>

            <RemoveCompetitorModal
                v-model:open="removeDialogOpen"
                :account="accountToRemove"
            />
        </div>
    </div>
</template>
