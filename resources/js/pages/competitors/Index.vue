<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import {
    confirmSuggestions,
    destroy,
    store,
    suggest,
    suggestStatus,
    sync,
} from '@/actions/App/Http/Controllers/CompetitorController';
import PlatformSelect from '@/components/PlatformSelect.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { platformIconSrc, platformLabel } from '@/lib/platforms';
import { useToastStore } from '@/stores/toastStore';

type Account = {
    id: number;
    platform: string;
    handle: string;
    display_name: string | null;
    avatar: string | null;
    url: string;
    posts_count?: number;
    last_synced_at: string | null;
};

type Suggestion = {
    platform: string;
    handle: string;
    url: string;
    display_name: string;
    avatar: string | null;
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

const props = defineProps<{
    accounts: Account[];
    platforms: string[];
    suggestions: Suggestion[];
    suggestRun?: SuggestRun | null;
}>();

defineOptions({
    layout: AppLayout,
});

const toast = useToastStore();

const selected = ref<Record<string, boolean>>({});
const localSuggestions = ref<Suggestion[]>([...props.suggestions]);
const suggesting = ref(false);
const suggestMessage = ref('');
let pollTimer: ReturnType<typeof setTimeout> | null = null;

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

function toggle(item: Suggestion): void {
    const key = `${item.platform}:${item.handle}`;
    selected.value[key] = !selected.value[key];
}

function clearPoll(): void {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}

onMounted(() => {
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
});

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function pollSuggestions(id: string, attempt = 0): Promise<void> {
    if (attempt > 60) {
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
        localSuggestions.value = payload.suggestions ?? [];
        selected.value = {};
        suggesting.value = false;
        suggestMessage.value =
            localSuggestions.value.length > 0
                ? `Found ${localSuggestions.value.length} competitors.`
                : 'No verified competitors found.';

        if (localSuggestions.value.length === 0) {
            toast.error('No verified competitor accounts found. Try again later.');
        } else {
            toast.success('Competitor picks ready.');
        }

        return;
    }

    if (payload.status === 'failed' || payload.status === 'missing') {
        suggesting.value = false;
        suggestMessage.value = payload.error || 'Suggestion failed.';
        toast.error(payload.error || 'Could not suggest competitors.');

        return;
    }

    suggestMessage.value = payload.status === 'processing' ? 'Finding…' : 'Queued…';
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
    confirmForm
        .transform(() => ({
            suggestions: selectedSuggestions.value,
        }))
        .post(confirmSuggestions.url(), { preserveScroll: true });
}

function remove(account: Account): void {
    router.delete(destroy.url(account.id));
}

function syncNow(account: Account): void {
    router.post(sync.url(account.id));
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Competitors" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-5xl">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                        Tracked accounts
                    </h1>
                    <p class="mt-1.5 text-sm text-snitch-ink/65 sm:text-base">
                        Cutout profiles from Instagram, TikTok, Facebook, and LinkedIn.
                    </p>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-ghost"
                        :disabled="suggesting"
                        @click="requestSuggestions"
                    >
                        {{ suggesting ? 'Finding…' : 'Suggest competitors' }}
                    </button>
                    <p
                        v-if="suggestMessage"
                        class="snitch-annotation text-base text-snitch-ink/70"
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
                            Asking for rivals, then verifying real public profiles.
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
                            <span class="relative z-10">
                                {{ form.processing ? 'Adding…' : 'Add' }}
                            </span>
                        </button>
                    </div>
                </div>
            </form>

            <section v-if="localSuggestions.length" class="mt-10">
                <h2 class="snitch-display text-2xl text-snitch-ink">
                    Polaroid picks
                </h2>
                <p class="mt-1.5 text-sm text-snitch-ink/65">
                    Select competitors to track, then confirm.
                </p>

                <div class="snitch-contact-reveal mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="(item, index) in localSuggestions"
                        :key="`${item.platform}:${item.handle}`"
                        type="button"
                        class="snitch-polaroid text-left transition"
                        :style="{
                            '--snitch-tilt': index % 2 === 0 ? '-1.5deg' : '1.2deg',
                        }"
                        :class="
                            selected[`${item.platform}:${item.handle}`]
                                ? 'ring-2 ring-snitch-spot ring-offset-2 ring-offset-transparent'
                                : ''
                        "
                        @click="toggle(item)"
                    >
                        <span
                            class="snitch-tape -top-2"
                            :class="index % 2 === 0 ? 'left-3' : 'right-3'"
                            aria-hidden="true"
                        />
                        <div class="snitch-polaroid-frame !aspect-square">
                            <img
                                v-if="item.avatar"
                                :src="item.avatar"
                                alt=""
                            />
                            <div
                                v-else
                                class="flex h-full items-center justify-center bg-snitch-teal/25 text-2xl font-semibold text-snitch-ink/60"
                            >
                                {{ item.display_name.slice(0, 1) }}
                            </div>
                        </div>
                        <p class="snitch-polaroid-caption">
                            @{{ item.handle }}
                        </p>
                        <div class="mt-1 px-0.5">
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
                            <p class="snitch-display mt-2 text-lg">
                                {{ item.display_name }}
                            </p>
                        </div>
                    </button>
                </div>

                <button
                    type="button"
                    class="snitch-btn snitch-btn-spot mt-6"
                    :disabled="selectedSuggestions.length === 0 || confirmForm.processing"
                    @click="submitConfirm"
                >
                    <span class="relative z-10">
                        Confirm {{ selectedSuggestions.length }} competitors
                    </span>
                </button>
            </section>

            <ul
                v-if="accounts.length"
                class="snitch-contact-reveal mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
            >
                <li
                    v-for="(account, index) in accounts"
                    :key="account.id"
                    class="snitch-cutout relative overflow-hidden p-5"
                    :data-platform="account.platform"
                    :style="{
                        '--snitch-tilt': index % 2 === 0 ? '-0.8deg' : '0.9deg',
                        transform: 'rotate(var(--snitch-tilt, 0deg))',
                    }"
                >
                    <img
                        :src="platformIconSrc(account.platform)"
                        alt=""
                        class="snitch-cutout-platform-mark"
                        width="72"
                        height="72"
                        aria-hidden="true"
                    />
                    <div class="relative z-10 flex items-center gap-2">
                        <img
                            :src="platformIconSrc(account.platform)"
                            :alt="`${platformLabel(account.platform)} logo`"
                            class="snitch-platform-logo size-5 shrink-0"
                            width="20"
                            height="20"
                        />
                        <span class="snitch-ink-label">
                            {{ platformLabel(account.platform) }}
                        </span>
                    </div>
                    <div class="relative z-10 mt-4 flex items-center gap-3">
                        <img
                            v-if="account.avatar"
                            :src="account.avatar"
                            alt=""
                            class="h-14 w-14 object-cover"
                            style="clip-path: polygon(4% 0, 100% 3%, 96% 100%, 0 97%)"
                        />
                        <div
                            v-else
                            class="flex h-14 w-14 items-center justify-center bg-snitch-teal/20 text-sm font-semibold"
                            style="clip-path: polygon(4% 0, 100% 3%, 96% 100%, 0 97%)"
                        >
                            {{ account.handle.slice(0, 2).toUpperCase() }}
                        </div>
                        <div class="min-w-0">
                            <p class="snitch-display truncate text-xl">
                                {{ account.display_name || account.handle }}
                            </p>
                            <p class="snitch-annotation text-lg">
                                @{{ account.handle }}
                            </p>
                        </div>
                    </div>
                    <p class="relative z-10 mt-3 text-xs text-snitch-ink/55">
                        {{ account.posts_count ?? 0 }} posts
                    </p>
                    <div class="relative z-10 mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                            @click="syncNow(account)"
                        >
                            Sync now
                        </button>
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                            @click="remove(account)"
                        >
                            Remove
                        </button>
                        <Link
                            v-if="account.url"
                            :href="account.url"
                            class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                            target="_blank"
                        >
                            Open
                        </Link>
                    </div>
                </li>
            </ul>

            <div
                v-else-if="!localSuggestions.length && !suggesting"
                class="snitch-scrap relative mx-auto mt-10 max-w-md p-8 text-center"
            >
                <span class="snitch-tape left-8 -top-2" aria-hidden="true" />
                <p class="snitch-display text-2xl">No cutouts yet</p>
                <p class="mt-2 text-sm text-snitch-ink/65">
                    Add a handle above, or ask Snitch to suggest competitors.
                </p>
            </div>
        </div>
    </div>
</template>
