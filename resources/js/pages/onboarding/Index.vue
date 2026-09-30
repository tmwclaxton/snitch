<script setup lang="ts">
import { Head, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import OnboardingController from '@/actions/App/Http/Controllers/OnboardingController';
import BillingController from '@/actions/App/Http/Controllers/Settings/BillingController';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { formatPenceAsGbp } from '@/lib/money';

type CompetitorRow = {
    id?: number;
    platform: string;
    handle: string;
    display_name: string | null;
    avatar: string | null;
    followers: number | null;
    url?: string | null;
    source?: string;
};

type TrackedBy = {
    count: number;
    since: string;
} | null;

const props = defineProps<{
    step: 'competitors' | 'reveal' | 'paywall';
    ownHandle: string | null;
    competitors: CompetitorRow[];
    trackedBy: TrackedBy;
    trialDays: number;
    trialCompetitorLimit: number;
    platformFeePence: number;
    subscribed: boolean;
    platforms: string[];
}>();

defineOptions({
    layout: PublicLayout,
});

setLayoutProps({ minimal: true });

const query = ref('');
const lookingUp = ref(false);
const searching = ref(false);
const searchError = ref<string | null>(null);
const results = ref<CompetitorRow[]>([]);
const selected = ref<CompetitorRow[]>([...props.competitors]);
const ownHandle = ref(props.ownHandle ?? '');

const form = useForm({
    own_handle: ownHandle.value,
    competitors: selected.value.map((row) => ({
        platform: row.platform,
        handle: row.handle,
        display_name: row.display_name,
        avatar: row.avatar,
        followers: row.followers,
        url: row.url ?? null,
    })),
});

const canAddMore = computed(
    () => selected.value.length < props.trialCompetitorLimit,
);

const feeLabel = computed(() =>
    formatPenceAsGbp(props.platformFeePence, { decimals: 2 }),
);

watch(selected, (rows) => {
    form.competitors = rows.map((row) => ({
        platform: row.platform,
        handle: row.handle,
        display_name: row.display_name,
        avatar: row.avatar,
        followers: row.followers,
        url: row.url ?? null,
    }));
    form.own_handle = ownHandle.value;
});

async function runSearch(): Promise<void> {
    const q = query.value.trim();
    searchError.value = null;

    if (q.length < 2) {
        results.value = [];

        return;
    }

    searching.value = true;

    try {
        const response = await fetch(
            OnboardingController.search.url({ query: { q } }),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        );
        const payload = (await response.json()) as { results?: CompetitorRow[] };
        results.value = payload.results ?? [];
    } catch {
        searchError.value = 'Search failed. Try again.';
    } finally {
        searching.value = false;
    }
}

async function runLookup(): Promise<void> {
    const q = query.value.trim();
    searchError.value = null;

    if (q.length < 2) {
        return;
    }

    lookingUp.value = true;

    try {
        const token = (
            document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null
        )?.content;

        const response = await fetch(OnboardingController.lookup.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ q, platform: 'instagram' }),
        });
        const payload = (await response.json()) as {
            result?: CompetitorRow | null;
            error?: string;
        };

        if (!response.ok || !payload.result) {
            searchError.value =
                payload.error ?? 'Could not find that account. Check the handle or profile URL.';

            return;
        }

        addCompetitor(payload.result);
        query.value = '';
        results.value = [];
    } catch {
        searchError.value = 'Lookup failed. Try again.';
    } finally {
        lookingUp.value = false;
    }
}

function addCompetitor(row: CompetitorRow): void {
    if (!canAddMore.value) {
        searchError.value = `Trial includes ${props.trialCompetitorLimit} competitors.`;

        return;
    }

    const key = `${row.platform}:${row.handle.toLowerCase()}`;

    if (
        selected.value.some(
            (item) => `${item.platform}:${item.handle.toLowerCase()}` === key,
        )
    ) {
        return;
    }

    selected.value = [...selected.value, row];
}

function removeCompetitor(row: CompetitorRow): void {
    selected.value = selected.value.filter(
        (item) =>
            !(
                item.platform === row.platform &&
                item.handle.toLowerCase() === row.handle.toLowerCase()
            ),
    );
}

function submitCompetitors(): void {
    form.own_handle = ownHandle.value;
    form.competitors = selected.value.map((row) => ({
        platform: row.platform,
        handle: row.handle,
        display_name: row.display_name,
        avatar: row.avatar,
        followers: row.followers,
        url: row.url ?? null,
    }));
    form.post(OnboardingController.store.url());
}

function continueFromReveal(): void {
    router.post(OnboardingController.continueToPaywall.url());
}

function startCheckout(): void {
    router.post(BillingController.checkout.url(), {
        product: 'platform',
        from: 'onboarding',
    });
}

function followerLabel(count: number | null): string {
    if (count == null) {
        return 'Followers unknown';
    }

    return `${count.toLocaleString('en-GB')} followers`;
}
</script>

<template>
    <div
        class="px-5 py-6 sm:px-8 sm:py-8"
        data-test="onboarding-page"
    >
        <Head title="Onboarding" />

        <div class="mx-auto max-w-2xl">
            <p class="snitch-ink-label">
                Step
                {{ step === 'competitors' ? '1' : step === 'reveal' ? '2' : '3' }}
                of 3
            </p>

            <template v-if="step === 'competitors'">
                <h1
                    class="snitch-display mt-2 text-3xl text-snitch-ink sm:text-4xl"
                    data-test="onboarding-step-competitors"
                >
                    Add competitors
                </h1>
                <p class="mt-3 text-[14px] text-snitch-ink/75">
                    Search accounts already in Snitch, or paste an @handle / profile URL.
                    Trial includes {{ trialCompetitorLimit }} competitors.
                </p>

                <label class="mt-5 block text-[14px] font-medium text-snitch-ink">
                    Your Instagram handle
                    <input
                        v-model="ownHandle"
                        type="text"
                        class="mt-1.5 w-full rounded border border-snitch-ink/15 bg-white px-3 py-2 text-[14px] outline-none focus:border-snitch-ink/40"
                        placeholder="@yourbrand"
                        data-test="onboarding-own-handle"
                    >
                </label>

                <label class="mt-4 block text-[14px] font-medium text-snitch-ink">
                    Find competitors
                    <div class="mt-1.5 flex flex-col gap-2 sm:flex-row">
                        <input
                            v-model="query"
                            type="search"
                            class="w-full rounded border border-snitch-ink/15 bg-white px-3 py-2 text-[14px] outline-none focus:border-snitch-ink/40"
                            placeholder="Search by name, @handle, or profile URL"
                            data-test="onboarding-search"
                            @keyup.enter="runSearch"
                        >
                        <button
                            type="button"
                            class="snitch-btn px-3 py-2 text-[14px]"
                            :disabled="searching"
                            @click="runSearch"
                        >
                            Search
                        </button>
                        <button
                            type="button"
                            class="rounded border border-snitch-ink/20 bg-white px-3 py-2 text-[14px]"
                            :disabled="lookingUp"
                            data-test="onboarding-lookup"
                            @click="runLookup"
                        >
                            Look up
                        </button>
                    </div>
                </label>

                <p
                    v-if="searchError"
                    class="mt-2 text-[14px] text-red-700"
                >
                    {{ searchError }}
                </p>

                <ul
                    v-if="results.length"
                    class="mt-3 space-y-2"
                    data-test="onboarding-search-results"
                >
                    <li
                        v-for="row in results"
                        :key="`${row.platform}:${row.handle}`"
                        class="flex items-center gap-3 rounded border border-snitch-ink/10 bg-white px-3 py-2"
                    >
                        <img
                            v-if="row.avatar"
                            :src="row.avatar"
                            alt=""
                            class="size-10 rounded-full object-cover"
                        >
                        <div
                            v-else
                            class="size-10 rounded-full bg-snitch-ink/10"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="text-[14px] font-medium text-snitch-ink">
                                @{{ row.handle }}
                            </p>
                            <p class="text-[14px] text-snitch-ink/65">
                                {{ row.display_name || row.platform }} · {{ followerLabel(row.followers) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="snitch-btn px-2.5 py-1.5 text-[14px]"
                            @click="addCompetitor(row)"
                        >
                            Add
                        </button>
                    </li>
                </ul>

                <div class="mt-5">
                    <p class="text-[12px] font-semibold uppercase tracking-wide text-snitch-ink/55">
                        Selected ({{ selected.length }}/{{ trialCompetitorLimit }})
                    </p>
                    <ul
                        v-if="selected.length"
                        class="mt-2 space-y-2"
                        data-test="onboarding-selected"
                    >
                        <li
                            v-for="row in selected"
                            :key="`sel-${row.platform}:${row.handle}`"
                            class="flex items-center gap-3 rounded border border-snitch-ink/10 bg-snitch-paper px-3 py-2"
                        >
                            <img
                                v-if="row.avatar"
                                :src="row.avatar"
                                alt=""
                                class="size-9 rounded-full object-cover"
                            >
                            <div class="min-w-0 flex-1 text-[14px]">
                                <p class="font-medium text-snitch-ink">
                                    @{{ row.handle }}
                                </p>
                                <p class="text-snitch-ink/65">
                                    {{ followerLabel(row.followers) }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="text-[14px] text-snitch-ink/70 underline"
                                @click="removeCompetitor(row)"
                            >
                                Remove
                            </button>
                        </li>
                    </ul>
                    <p
                        v-else
                        class="mt-2 text-[14px] text-snitch-ink/65"
                    >
                        Add at least one competitor to continue.
                    </p>
                </div>

                <button
                    type="button"
                    class="snitch-btn mt-6 px-4 py-2 text-[14px] disabled:opacity-50"
                    :disabled="selected.length === 0 || !ownHandle.trim() || form.processing"
                    data-test="onboarding-continue-competitors"
                    @click="submitCompetitors"
                >
                    Continue
                </button>
            </template>

            <template v-else-if="step === 'reveal'">
                <h1
                    class="snitch-display mt-2 text-3xl text-snitch-ink sm:text-4xl"
                    data-test="onboarding-step-reveal"
                >
                    The reveal
                </h1>

                <div
                    v-if="trackedBy && trackedBy.count > 0"
                    class="mt-5 rounded-md border border-amber-400/40 bg-[#141311] px-4 py-5 text-[14px] text-[#F5F0E6]"
                    data-test="onboarding-reveal-watched"
                >
                    <p class="text-[12px] font-semibold uppercase tracking-[0.14em] text-amber-300">
                        Good thing you're here
                    </p>
                    <p class="mt-2 font-display text-2xl text-[#FFF8E7]">
                        Your competitors are already using Snitch
                    </p>
                    <p class="mt-2 text-[14px] text-[#E8DFD0]">
                        {{ trackedBy.count === 1
                            ? '1 account is already watching you.'
                            : `${trackedBy.count} accounts are already watching you.` }}
                        Since {{ trackedBy.since }}.
                    </p>
                </div>

                <div
                    v-else
                    class="mt-5 rounded-md border border-snitch-ink/10 bg-white px-4 py-5 text-[14px]"
                    data-test="onboarding-reveal-clear"
                >
                    <p class="font-display text-2xl text-snitch-ink">
                        Congrats, you now have the advantage.
                    </p>
                    <p class="mt-2 text-snitch-ink/75">
                        None of your competitors use Snitch.
                    </p>
                </div>

                <button
                    type="button"
                    class="snitch-btn mt-6 px-4 py-2 text-[14px]"
                    data-test="onboarding-continue-reveal"
                    @click="continueFromReveal"
                >
                    Continue
                </button>
            </template>

            <template v-else>
                <h1
                    class="snitch-display mt-2 text-3xl text-snitch-ink sm:text-4xl"
                    data-test="onboarding-step-paywall"
                >
                    Start your free trial
                </h1>
                <p class="mt-3 text-[14px] text-snitch-ink/75">
                    {{ trialDays }}-day free trial. Card taken at checkout. Then {{ feeLabel }}/month
                    platform fee plus usage credits. {{ trialCompetitorLimit }} competitors included
                    in the trial.
                </p>

                <button
                    type="button"
                    class="snitch-btn mt-6 px-4 py-2 text-[14px]"
                    data-test="onboarding-start-trial"
                    @click="startCheckout"
                >
                    Start {{ trialDays }}-day trial
                </button>
            </template>
        </div>
    </div>
</template>
