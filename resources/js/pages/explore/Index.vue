<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Compass, FilterX, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import { index as exploreIndex } from '@/actions/App/Http/Controllers/ExploreController';
import FeedContactCell from '@/components/FeedContactCell.vue';
import PaperSelect from '@/components/PaperSelect.vue';
import PaperTermPicker from '@/components/PaperTermPicker.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { analysisDimensionIcon } from '@/lib/analysisTerms';
import type { PostMetrics } from '@/lib/metrics';
import { platformIconSrc, platformLabel } from '@/lib/platforms';
import { humanizeTagLabel } from '@/lib/posts';

type AnalysisTermOption = {
    id: number;
    dimension: string;
    slug: string;
    label: string;
    section: string;
    count: number;
};

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
        custom_tags?: string[] | null;
        term_labels?: Array<{ dimension: string; slug: string; label: string }>;
    } | null;
    winner_insight?: { score: number } | null;
};

const props = defineProps<{
    posts: {
        data: Post[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        q: string | null;
        custom_tag: string | null;
        hook_types: string[];
        topics: string[];
        visual_crafts: string[];
        platform: string | null;
    };
    terms: {
        hook_type: AnalysisTermOption[];
        topic: AnalysisTermOption[];
        visual_craft: AnalysisTermOption[];
    };
    platforms: string[];
}>();

defineOptions({
    layout: AppLayout,
});

const searchDraft = ref(props.filters.q ?? '');
const hookPickerOpen = ref(false);
const topicPickerOpen = ref(false);
const craftPickerOpen = ref(false);

watch(
    () => props.filters.q,
    (value) => {
        searchDraft.value = value ?? '';
    },
);

const platformOptions = computed(() => [
    { value: 'all', label: 'All platforms' },
    ...props.platforms.map((platform) => ({
        value: platform,
        label: platformLabel(platform),
        iconSrc: platformIconSrc(platform),
    })),
]);

const selectedPlatform = computed(() => props.filters.platform ?? 'all');

const hookPickerOptions = computed(() =>
    props.terms.hook_type.map((term) => ({
        slug: term.slug,
        label: term.label,
        section: term.section,
        count: term.count,
    })),
);

const topicPickerOptions = computed(() =>
    props.terms.topic.map((term) => ({
        slug: term.slug,
        label: term.label,
        section: term.section,
        count: term.count,
    })),
);

const craftPickerOptions = computed(() =>
    props.terms.visual_craft.map((term) => ({
        slug: term.slug,
        label: term.label,
        section: term.section,
        count: term.count,
    })),
);

const frameCount = computed(() => props.posts.data.length);

const hasActiveFilters = computed(
    () =>
        props.filters.q != null ||
        props.filters.custom_tag != null ||
        props.filters.hook_types.length > 0 ||
        props.filters.topics.length > 0 ||
        props.filters.visual_crafts.length > 0 ||
        props.filters.platform != null,
);

function labelForSlug(dimension: 'hook_type' | 'topic' | 'visual_craft', slug: string): string {
    return props.terms[dimension].find((term) => term.slug === slug)?.label ?? slug;
}

function summaryFor(
    dimension: 'hook_type' | 'topic' | 'visual_craft',
    slugs: string[],
    empty: string,
): string {
    if (slugs.length === 0) {
        return empty;
    }

    if (slugs.length === 1) {
        return labelForSlug(dimension, slugs[0]);
    }

    return `${slugs.length} selected`;
}

const hookSummary = computed(() =>
    summaryFor('hook_type', props.filters.hook_types, 'Any hook type'),
);
const topicSummary = computed(() => summaryFor('topic', props.filters.topics, 'Any topic'));
const craftSummary = computed(() =>
    summaryFor('visual_craft', props.filters.visual_crafts, 'Any visual craft'),
);

const activeChips = computed(() => {
    const chips: Array<{
        key: string;
        label: string;
        icon: Component;
        clear: () => void;
    }> = [];

    if (props.filters.q) {
        chips.push({
            key: 'q',
            label: `Search: ${props.filters.q}`,
            icon: analysisDimensionIcon('search'),
            clear: () => {
                searchDraft.value = '';
                visitFilters(currentFilters({ q: null }));
            },
        });
    }

    if (props.filters.custom_tag) {
        chips.push({
            key: 'custom_tag',
            label: humanizeTagLabel(props.filters.custom_tag),
            icon: analysisDimensionIcon('custom'),
            clear: () => visitFilters(currentFilters({ custom_tag: null })),
        });
    }

    for (const slug of props.filters.hook_types) {
        chips.push({
            key: `hook:${slug}`,
            label: labelForSlug('hook_type', slug),
            icon: analysisDimensionIcon('hook_type'),
            clear: () =>
                visitFilters(
                    currentFilters({
                        hook_types: props.filters.hook_types.filter((value) => value !== slug),
                    }),
                ),
        });
    }

    for (const slug of props.filters.topics) {
        chips.push({
            key: `topic:${slug}`,
            label: labelForSlug('topic', slug),
            icon: analysisDimensionIcon('topic'),
            clear: () =>
                visitFilters(
                    currentFilters({
                        topics: props.filters.topics.filter((value) => value !== slug),
                    }),
                ),
        });
    }

    for (const slug of props.filters.visual_crafts) {
        chips.push({
            key: `craft:${slug}`,
            label: labelForSlug('visual_craft', slug),
            icon: analysisDimensionIcon('visual_craft'),
            clear: () =>
                visitFilters(
                    currentFilters({
                        visual_crafts: props.filters.visual_crafts.filter((value) => value !== slug),
                    }),
                ),
        });
    }

    if (props.filters.platform) {
        chips.push({
            key: 'platform',
            label: platformLabel(props.filters.platform),
            icon: analysisDimensionIcon('custom'),
            clear: () => visitFilters(currentFilters({ platform: null })),
        });
    }

    return chips;
});

function visitFilters(next: {
    q: string | null;
    custom_tag: string | null;
    hook_types: string[];
    topics: string[];
    visual_crafts: string[];
    platform: string | null;
}): void {
    router.get(
        exploreIndex.url(),
        {
            q: next.q,
            custom_tag: next.custom_tag,
            hook_types: next.hook_types.length > 0 ? next.hook_types : undefined,
            topics: next.topics.length > 0 ? next.topics : undefined,
            visual_crafts: next.visual_crafts.length > 0 ? next.visual_crafts : undefined,
            platform: next.platform,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function currentFilters(overrides: Partial<{
    q: string | null;
    custom_tag: string | null;
    hook_types: string[];
    topics: string[];
    visual_crafts: string[];
    platform: string | null;
}> = {}) {
    return {
        q: props.filters.q,
        custom_tag: props.filters.custom_tag,
        hook_types: props.filters.hook_types,
        topics: props.filters.topics,
        visual_crafts: props.filters.visual_crafts,
        platform: props.filters.platform,
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

function onHookTypesChange(value: string[]): void {
    visitFilters(currentFilters({ hook_types: value }));
}

function onTopicsChange(value: string[]): void {
    visitFilters(currentFilters({ topics: value }));
}

function onVisualCraftsChange(value: string[]): void {
    visitFilters(currentFilters({ visual_crafts: value }));
}

function clearFilters(): void {
    searchDraft.value = '';
    visitFilters({
        q: null,
        custom_tag: null,
        hook_types: [],
        topics: [],
        visual_crafts: [],
        platform: null,
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
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Explore" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl">
            <header class="flex flex-wrap items-end justify-between gap-4 border-b border-snitch-ink/10 pb-5">
                <div class="min-w-0">
                    <p class="snitch-ink-label">Snitch / Explore</p>
                    <h1 class="snitch-display mt-1.5 text-3xl text-snitch-ink sm:text-4xl">
                        Craft catalogue
                    </h1>
                    <p class="mt-1.5 text-sm text-snitch-ink/65 sm:text-base">Open a catalogue picker, select multiple hooks, topics, or crafts, then skim the matches.</p>
                </div>
                <div class="text-right">
                    <p class="snitch-display text-2xl tabular-nums text-snitch-ink sm:text-3xl">
                        {{ frameCount }}
                    </p>
                    <p class="snitch-ink-label mt-0.5">
                        {{ frameCount === 1 ? 'Match' : 'Matches' }}
                        <span v-if="hasActiveFilters"> filtered</span>
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
                            placeholder="Caption, concept, hook, custom tag…"
                            aria-label="Search catalogue"
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

                <div class="snitch-filter-field">
                    <span class="inline-flex items-center gap-1.5">
                        <component
                            :is="analysisDimensionIcon('hook_type')"
                            class="size-3 shrink-0 opacity-70"
                            aria-hidden="true"
                        />
                        Hook type
                    </span>
                    <button
                        type="button"
                        class="snitch-platform-select-trigger w-full rounded-none text-left text-sm"
                        :class="filters.hook_types.length ? 'font-medium' : 'text-snitch-ink/55'"
                        aria-label="Open hook type catalogue"
                        @click="hookPickerOpen = true"
                    >
                        <span class="inline-flex min-w-0 items-center gap-1.5">
                            <component
                                :is="analysisDimensionIcon('hook_type')"
                                class="size-3.5 shrink-0 opacity-70"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ hookSummary }}</span>
                        </span>
                    </button>
                </div>

                <div class="snitch-filter-field">
                    <span class="inline-flex items-center gap-1.5">
                        <component
                            :is="analysisDimensionIcon('topic')"
                            class="size-3 shrink-0 opacity-70"
                            aria-hidden="true"
                        />
                        Topic
                    </span>
                    <button
                        type="button"
                        class="snitch-platform-select-trigger w-full rounded-none text-left text-sm"
                        :class="filters.topics.length ? 'font-medium' : 'text-snitch-ink/55'"
                        aria-label="Open topic catalogue"
                        @click="topicPickerOpen = true"
                    >
                        <span class="inline-flex min-w-0 items-center gap-1.5">
                            <component
                                :is="analysisDimensionIcon('topic')"
                                class="size-3.5 shrink-0 opacity-70"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ topicSummary }}</span>
                        </span>
                    </button>
                </div>

                <div class="snitch-filter-field">
                    <span class="inline-flex items-center gap-1.5">
                        <component
                            :is="analysisDimensionIcon('visual_craft')"
                            class="size-3 shrink-0 opacity-70"
                            aria-hidden="true"
                        />
                        Visual craft
                    </span>
                    <button
                        type="button"
                        class="snitch-platform-select-trigger w-full rounded-none text-left text-sm"
                        :class="filters.visual_crafts.length ? 'font-medium' : 'text-snitch-ink/55'"
                        aria-label="Open visual craft catalogue"
                        @click="craftPickerOpen = true"
                    >
                        <span class="inline-flex min-w-0 items-center gap-1.5">
                            <component
                                :is="analysisDimensionIcon('visual_craft')"
                                class="size-3.5 shrink-0 opacity-70"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ craftSummary }}</span>
                        </span>
                    </button>
                </div>

                <label class="snitch-filter-field">
                    <span>Platform</span>
                    <PaperSelect
                        id="explore-filter-platform"
                        :model-value="selectedPlatform"
                        :options="platformOptions"
                        aria-label="Filter by platform"
                        @update:model-value="onPlatformChange"
                    />
                </label>
            </form>

            <PaperTermPicker
                v-model:open="hookPickerOpen"
                title="Hook types"
                description="Browse every hook pattern by section. Select as many as you want."
                dimension="hook_type"
                :options="hookPickerOptions"
                :model-value="filters.hook_types"
                @update:model-value="onHookTypesChange"
            />
            <PaperTermPicker
                v-model:open="topicPickerOpen"
                title="Topics"
                description="Browse every topic by section. Select as many as you want."
                dimension="topic"
                :options="topicPickerOptions"
                :model-value="filters.topics"
                @update:model-value="onTopicsChange"
            />
            <PaperTermPicker
                v-model:open="craftPickerOpen"
                title="Visual crafts"
                description="Browse every visual craft by section. Select as many as you want."
                dimension="visual_craft"
                :options="craftPickerOptions"
                :model-value="filters.visual_crafts"
                @update:model-value="onVisualCraftsChange"
            />

            <div
                v-if="hasActiveFilters"
                class="mt-3 flex flex-wrap items-center gap-2"
            >
                <button
                    v-for="chip in activeChips"
                    :key="chip.key"
                    type="button"
                    class="inline-flex items-center gap-1.5 border border-snitch-ink/15 bg-[color-mix(in_oklab,var(--snitch-spot)_22%,var(--snitch-paper))] px-2.5 py-1 text-xs font-medium text-snitch-ink shadow-[1px_1px_0_color-mix(in_oklab,var(--snitch-spot)_25%,transparent)] transition hover:border-snitch-ink/35"
                    :aria-label="`Remove ${chip.label}`"
                    @click="chip.clear"
                >
                    <img
                        v-if="chip.key === 'platform' && filters.platform"
                        :src="platformIconSrc(filters.platform)"
                        alt=""
                        class="snitch-platform-logo size-3 shrink-0"
                        width="12"
                        height="12"
                    >
                    <component
                        v-else
                        :is="chip.icon"
                        class="size-3 shrink-0 opacity-70"
                        aria-hidden="true"
                    />
                    <span>{{ chip.label }}</span>
                    <X class="size-3 shrink-0 text-snitch-ink/45" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    class="ms-auto inline-flex items-center gap-1.5 text-sm font-medium text-snitch-ink/55 underline decoration-snitch-ink/20 underline-offset-4 transition hover:text-snitch-ink"
                    @click="clearFilters"
                >
                    <FilterX class="size-3.5 shrink-0" aria-hidden="true" />
                    Clear all
                </button>
            </div>

            <div
                v-if="posts.data.length"
                class="snitch-contact-sheet snitch-contact-reveal mt-6 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
            >
                <div class="snitch-contact-sheet-rail col-span-full">
                    <p>Explore sheet</p>
                    <p>{{ frameCount }} exposures</p>
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
                        <img
                            src="/images/marketing/empty-404.jpg"
                            alt=""
                            class="h-full w-full object-cover"
                        >
                    </div>
                </div>
                <p class="snitch-display text-2xl text-snitch-ink">
                    {{ hasActiveFilters ? 'No matches' : 'Nothing tagged yet' }}
                </p>
                <p class="mt-2 text-sm text-snitch-ink/65">
                    <template v-if="hasActiveFilters">
                        Try another hook, topic, or craft - or clear the filters.
                    </template>
                    <template v-else>
                        Sync competitors and wait for analysis to fill the catalogue.
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
                    <Compass class="size-3.5 shrink-0" aria-hidden="true" />
                    Waiting for analysis
                </p>
            </div>

            <nav
                v-if="posts.links.length > 3"
                class="mt-8 flex flex-wrap justify-center gap-2"
                aria-label="Pagination"
            >
                <template
                    v-for="(link, index) in posts.links"
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
