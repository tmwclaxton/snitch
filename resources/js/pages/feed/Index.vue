<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Clapperboard, FilterX, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import { index as feedIndex } from '@/actions/App/Http/Controllers/FeedController';
import FeedContactCell from '@/components/FeedContactCell.vue';
import PaperSelect from '@/components/PaperSelect.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import SnitchImage from '@/components/SnitchImage.vue';
import SnitchSkeleton from '@/components/SnitchSkeleton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { PostMetrics } from '@/lib/metrics';
import { platformIconSrc, platformLabel } from '@/lib/platforms';

type Post = {
    id: number;
    platform: string;
    type: string;
    url: string | null;
    caption: string | null;
    media_url: string | null;
    media_availability?: string | null;
    metrics?: PostMetrics | null;
    posted_at: string | null;
    embed?: EmbedConfig | null;
    tracked_account?: { id?: number; handle: string; display_name: string | null };
    analysis?: {
        status: string;
        hook: string | null;
        concept?: string | null;
        topics?: string[] | null;
    } | null;
    winner_insight?: { score: number } | null;
};

const props = defineProps<{
    posts?: {
        data: Post[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    } | null;
    filters: {
        q: string | null;
        platform: string | null;
        type: string | null;
    };
    platforms: string[];
    types: string[];
}>();

defineOptions({
    layout: AppLayout,
});

const platformOptions = computed(() => [
    { value: 'all', label: 'All platforms' },
    ...props.platforms.map((platform) => ({
        value: platform,
        label: platformLabel(platform),
        iconSrc: platformIconSrc(platform),
    })),
]);

const typeOptions = computed(() => [
    { value: 'all', label: 'Any type' },
    ...props.types.map((type) => ({
        value: type,
        label: type.charAt(0).toUpperCase() + type.slice(1),
    })),
]);

const searchDraft = ref(props.filters.q ?? '');

watch(
    () => props.filters.q,
    (value) => {
        searchDraft.value = value ?? '';
    },
);

const selectedPlatform = computed(() => props.filters.platform ?? 'all');
const selectedType = computed(() => props.filters.type ?? 'all');

const postsLoaded = computed(() => props.posts != null);
const paginationLinks = computed(() => props.posts?.links ?? []);

const hasActiveFilters = computed(
    () =>
        props.filters.q != null ||
        props.filters.platform != null ||
        props.filters.type != null,
);

function visitFilters(next: {
    q: string | null;
    platform: string | null;
    type: string | null;
}): void {
    router.get(feedIndex.url(), next, {
        preserveState: true,
        preserveScroll: true,
    });
}

function currentFilters(overrides: Partial<{
    q: string | null;
    platform: string | null;
    type: string | null;
}> = {}) {
    return {
        q: props.filters.q,
        platform: props.filters.platform,
        type: props.filters.type,
        ...overrides,
    };
}

function onSearchSubmit(): void {
    const trimmed = searchDraft.value.trim();
    visitFilters(currentFilters({ q: trimmed === '' ? null : trimmed }));
}

function onPlatformChange(value: string): void {
    visitFilters(currentFilters({ platform: value === 'all' ? null : value }));
}

function onTypeChange(value: string): void {
    visitFilters(currentFilters({ type: value === 'all' ? null : value }));
}

function clearFilters(): void {
    searchDraft.value = '';
    visitFilters({
        q: null,
        platform: null,
        type: null,
    });
}

function accountHref(post: Post): string | null {
    const id = post.tracked_account?.id;

    if (id == null) {
        return null;
    }

    return competitorShow.url(id);
}

function paginationLabel(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/<[^>]+>/g, '')
        .trim();
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-2 py-6 sm:px-3 sm:py-8">
        <Head title="Feed" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="snitch-app-canvas">
            <header class="border-b border-snitch-ink/10 pb-5">
                <div class="min-w-0 max-w-xl">
                    <p class="snitch-ink-label">Snitch / Feed</p>
                    <h1 class="snitch-display mt-1.5 text-3xl text-snitch-ink sm:text-4xl">
                        Feed
                    </h1>
                    <p class="mt-1.5 text-sm text-snitch-ink/65 sm:text-base">
                        Metrics, hooks, and craft tags at a glance - open a frame for the full dossier.
                    </p>
                </div>
            </header>

            <form
                class="snitch-filter-bar snitch-explore-filters mt-6"
                @submit.prevent="onSearchSubmit"
            >
                <label class="snitch-filter-field snitch-explore-search">
                    <span>Search</span>
                    <div class="flex min-w-0 gap-2">
                        <input
                            v-model="searchDraft"
                            type="search"
                            class="snitch-platform-select-trigger min-w-0 flex-1 rounded-none px-2 py-1.5 text-sm text-snitch-ink outline-none placeholder:text-snitch-ink/35"
                            placeholder="Caption, handle, concept, hook…"
                            aria-label="Search feed"
                        >
                        <button
                            type="submit"
                            class="snitch-btn snitch-btn-spot shrink-0 px-3 py-1.5 text-sm"
                        >
                            <span class="relative z-10 inline-flex items-center gap-1.5">
                                <Search class="size-3.5 shrink-0" aria-hidden="true" />
                                Search
                            </span>
                        </button>
                    </div>
                </label>
                <label class="snitch-filter-field">
                    <span>Platform</span>
                    <PaperSelect
                        id="feed-filter-platform"
                        :model-value="selectedPlatform"
                        :options="platformOptions"
                        aria-label="Filter by platform"
                        @update:model-value="onPlatformChange"
                    />
                </label>
                <label class="snitch-filter-field">
                    <span>Type</span>
                    <PaperSelect
                        id="feed-filter-type"
                        :model-value="selectedType"
                        :options="typeOptions"
                        aria-label="Filter by content type"
                        @update:model-value="onTypeChange"
                    />
                </label>
            </form>

            <div
                v-if="hasActiveFilters"
                class="mt-3 flex flex-wrap items-center gap-2"
            >
                <button
                    v-if="filters.q"
                    type="button"
                    class="inline-flex items-center gap-1.5 border border-snitch-ink/15 bg-[color-mix(in_oklab,var(--snitch-spot)_22%,var(--snitch-paper))] px-2.5 py-1 text-xs font-medium text-snitch-ink shadow-[1px_1px_0_color-mix(in_oklab,var(--snitch-spot)_25%,transparent)] transition hover:border-snitch-ink/35"
                    :aria-label="`Remove search ${filters.q}`"
                    @click="searchDraft = ''; visitFilters(currentFilters({ q: null }))"
                >
                    <Search class="size-3 shrink-0 opacity-70" aria-hidden="true" />
                    <span>Search: {{ filters.q }}</span>
                    <X class="size-3 shrink-0 text-snitch-ink/45" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    class="ms-auto inline-flex items-center gap-1.5 text-sm font-medium text-snitch-ink/55 underline decoration-snitch-ink/20 underline-offset-4 transition hover:text-snitch-ink"
                    @click="clearFilters"
                >
                    <FilterX class="size-3.5 shrink-0" aria-hidden="true" />
                    Clear filters
                </button>
            </div>

            <div
                v-if="!postsLoaded"
                class="snitch-contact-sheet snitch-contact-sheet-proof mt-6 grid"
                aria-live="polite"
                aria-busy="true"
            >
                <div class="snitch-contact-sheet-rail col-span-full">
                    <p>Proof sheet</p>
                    <p>developing frames</p>
                </div>

                <div
                    v-for="index in 8"
                    :key="`feed-skel-${index}`"
                    class="p-2"
                >
                    <SnitchSkeleton
                        variant="polaroid"
                        width="100%"
                        :label="`Loading frame ${index}`"
                    />
                </div>
            </div>

            <div
                v-else-if="posts && posts.data.length"
                class="snitch-contact-sheet snitch-contact-sheet-proof snitch-contact-reveal mt-6 grid"
            >
                <div class="snitch-contact-sheet-rail col-span-full">
                    <p>Proof sheet</p>
                </div>

                <FeedContactCell
                    v-for="(post, index) in posts.data"
                    :key="post.id"
                    :post="post"
                    :index="index"
                    :account-href="accountHref(post)"
                />
            </div>

            <div
                v-else
                class="snitch-scrap relative mx-auto mt-8 max-w-md p-8 text-center sm:p-10"
            >
                <span class="snitch-tape left-8 -top-2" aria-hidden="true" />
                <div
                    class="snitch-polaroid mx-auto mb-5 max-w-[11rem]"
                    style="--snitch-tilt: -1.2deg"
                >
                    <div class="snitch-polaroid-frame !aspect-square">
                        <SnitchImage
                            src="/images/marketing/empty-404.jpg"
                            alt=""
                            aspect-ratio="1 / 1"
                            class="block size-full"
                            img-class="h-full w-full object-cover"
                            fallback="paper"
                        />
                    </div>
                </div>
                <p class="snitch-display text-2xl text-snitch-ink">
                    {{ hasActiveFilters ? 'No frames match' : 'No frames yet' }}
                </p>
                <p class="mt-2 text-sm text-snitch-ink/65">
                    <template v-if="hasActiveFilters">
                        Try another search, platform, or type - or clear the filters.
                    </template>
                    <template v-else>
                        Add snitches and sync to fill the contact sheet.
                    </template>
                </p>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="snitch-btn snitch-btn-ghost mt-5"
                    @click="clearFilters"
                >
                    <FilterX class="relative z-10 size-4 shrink-0" aria-hidden="true" />
                    <span class="relative z-10">Clear filters</span>
                </button>
                <p
                    v-else
                    class="mt-5 inline-flex items-center justify-center gap-2 text-xs uppercase tracking-wide text-snitch-ink/40"
                >
                    <Clapperboard class="size-3.5 shrink-0" aria-hidden="true" />
                    Waiting for sync
                </p>
            </div>

            <nav
                v-if="paginationLinks.length > 3"
                class="mt-8 flex flex-wrap justify-center gap-2"
                aria-label="Pagination"
            >
                <template
                    v-for="(link, index) in paginationLinks"
                    :key="`${link.label}-${index}`"
                >
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                        :class="link.active ? 'snitch-btn-spot' : ''"
                        preserve-scroll
                    >
                        {{ paginationLabel(link.label) }}
                    </Link>
                    <span
                        v-else
                        class="px-3 py-1.5 text-sm text-snitch-ink/35"
                    >
                        {{ paginationLabel(link.label) }}
                    </span>
                </template>
            </nav>
        </div>
    </div>
</template>
