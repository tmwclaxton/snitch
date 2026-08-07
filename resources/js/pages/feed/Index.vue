<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as feedIndex, show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import PaperSelect from '@/components/PaperSelect.vue';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import PlatformEmbed from '@/components/PlatformEmbed.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { platformIconSrc, platformLabel } from '@/lib/platforms';

type Post = {
    id: number;
    platform: string;
    type: string;
    url: string | null;
    caption: string | null;
    media_url: string | null;
    posted_at: string | null;
    embed?: EmbedConfig | null;
    tracked_account?: { handle: string; display_name: string | null };
    analysis?: { status: string; hook: string | null } | null;
};

const props = defineProps<{
    posts: {
        data: Post[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        platform: string | null;
        type: string | null;
        account: number | null;
    };
    platforms: string[];
    types: string[];
    accounts: Array<{ id: number; handle: string; platform: string; display_name: string | null }>;
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

const accountOptions = computed(() => [
    { value: 'all', label: 'All accounts' },
    ...props.accounts.map((account) => ({
        value: String(account.id),
        label: `@${account.handle}`,
        iconSrc: platformIconSrc(account.platform),
    })),
]);

const selectedPlatform = computed(() => props.filters.platform ?? 'all');
const selectedType = computed(() => props.filters.type ?? 'all');
const selectedAccount = computed(() =>
    props.filters.account != null ? String(props.filters.account) : 'all',
);

const frameCount = computed(() => props.posts.data.length);

const hasActiveFilters = computed(
    () =>
        props.filters.platform != null ||
        props.filters.type != null ||
        props.filters.account != null,
);

function visitFilters(next: {
    platform: string | null;
    type: string | null;
    account: number | null;
}): void {
    router.get(feedIndex.url(), next, {
        preserveState: true,
        preserveScroll: true,
    });
}

function onPlatformChange(value: string): void {
    visitFilters({
        platform: value === 'all' ? null : value,
        type: props.filters.type,
        account: props.filters.account,
    });
}

function onTypeChange(value: string): void {
    visitFilters({
        platform: props.filters.platform,
        type: value === 'all' ? null : value,
        account: props.filters.account,
    });
}

function onAccountChange(value: string): void {
    visitFilters({
        platform: props.filters.platform,
        type: props.filters.type,
        account: value === 'all' ? null : Number(value),
    });
}

function clearFilters(): void {
    visitFilters({
        platform: null,
        type: null,
        account: null,
    });
}

function frameIndex(index: number): string {
    return String(index + 1).padStart(2, '0');
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
        <Head title="Feed" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl">
            <header class="flex flex-wrap items-end justify-between gap-4 border-b border-snitch-ink/10 pb-5">
                <div class="min-w-0 max-w-xl">
                    <p class="snitch-ink-label">Snitch / Feed</p>
                    <h1 class="snitch-display mt-1.5 text-3xl text-snitch-ink sm:text-4xl">
                        Contact sheet
                    </h1>
                    <p class="mt-1.5 text-sm text-snitch-ink/65 sm:text-base">
                        Graded frames from every tracked competitor.
                    </p>
                </div>
                <div class="text-right">
                    <p class="snitch-display text-2xl tabular-nums text-snitch-ink sm:text-3xl">
                        {{ frameCount }}
                    </p>
                    <p class="snitch-ink-label mt-0.5">
                        {{ frameCount === 1 ? 'Frame' : 'Frames' }}
                        <span v-if="hasActiveFilters"> filtered</span>
                    </p>
                </div>
            </header>

            <div class="snitch-filter-bar mt-6">
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
                <label class="snitch-filter-field">
                    <span>Account</span>
                    <PaperSelect
                        id="feed-filter-account"
                        :model-value="selectedAccount"
                        :options="accountOptions"
                        aria-label="Filter by account"
                        @update:model-value="onAccountChange"
                    />
                </label>
            </div>

            <div
                v-if="hasActiveFilters"
                class="mt-3 flex justify-end"
            >
                <button
                    type="button"
                    class="text-sm font-medium text-snitch-ink/55 underline decoration-snitch-ink/20 underline-offset-4 transition hover:text-snitch-ink"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
            </div>

            <div
                v-if="posts.data.length"
                class="snitch-contact-sheet snitch-contact-reveal mt-6 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
            >
                <div class="snitch-contact-sheet-rail col-span-full">
                    <p>Proof sheet</p>
                    <p>{{ frameCount }} exposures</p>
                </div>

                <article
                    v-for="(post, index) in posts.data"
                    :key="post.id"
                    class="snitch-contact-cell group"
                >
                    <div class="snitch-contact-cell-frame">
                        <span class="snitch-contact-cell-index">{{ frameIndex(index) }}</span>
                        <PlatformEmbed
                            :embed="post.embed"
                            :media-url="post.media_url"
                            :post-url="post.url"
                            :platform="post.platform"
                            compact
                            lazy
                        />
                    </div>
                    <Link
                        :href="feedShow.url(post.id)"
                        class="snitch-contact-cell-meta snitch-contact-cell-meta-link"
                    >
                        <span class="snitch-ink-label">
                            {{ platformLabel(post.platform) }} · {{ post.type }}
                        </span>
                        <p class="snitch-annotation">
                            @{{ post.tracked_account?.handle }}
                        </p>
                        <p
                            v-if="post.analysis?.hook"
                            class="line-clamp-2"
                        >
                            {{ post.analysis.hook }}
                        </p>
                        <p
                            v-else-if="post.analysis?.status === 'pending' || post.analysis?.status === 'processing'"
                        >
                            Analysis pending…
                        </p>
                    </Link>
                </article>
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
                        />
                    </div>
                </div>
                <p class="snitch-display text-2xl text-snitch-ink">
                    {{ hasActiveFilters ? 'No frames match' : 'No frames yet' }}
                </p>
                <p class="mt-2 text-sm text-snitch-ink/65">
                    <template v-if="hasActiveFilters">
                        Try another platform, type, or account - or clear the filters.
                    </template>
                    <template v-else>
                        Add competitors and sync to fill the contact sheet.
                    </template>
                </p>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="snitch-btn snitch-btn-ghost mt-5"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
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
