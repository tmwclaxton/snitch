<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { index as feedIndex, show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import AppLayout from '@/layouts/AppLayout.vue';

type Post = {
    id: number;
    platform: string;
    type: string;
    caption: string | null;
    media_url: string | null;
    posted_at: string | null;
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

function applyFilter(key: 'platform' | 'type' | 'account', value: string | number | null): void {
    router.get(
        feedIndex.url(),
        {
            platform: key === 'platform' ? value : props.filters.platform,
            type: key === 'type' ? value : props.filters.type,
            account: key === 'account' ? value : props.filters.account,
        },
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full p-6">
        <Head title="Feed" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl">
            <h1 class="snitch-display text-4xl text-snitch-ink">
                Contact sheet
            </h1>
            <p class="mt-2 text-snitch-ink/65">
                Graded frames from every tracked competitor.
            </p>

            <div class="mt-6 flex flex-wrap gap-2">
                <button
                    type="button"
                    class="snitch-stamp cursor-pointer"
                    :class="!filters.platform ? 'snitch-stamp-active' : ''"
                    @click="applyFilter('platform', null)"
                >
                    All platforms
                </button>
                <button
                    v-for="platform in platforms"
                    :key="platform"
                    type="button"
                    class="snitch-stamp cursor-pointer"
                    :class="filters.platform === platform ? 'snitch-stamp-active' : ''"
                    @click="applyFilter('platform', platform)"
                >
                    {{ platform }}
                </button>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    v-for="type in types"
                    :key="type"
                    type="button"
                    class="snitch-stamp snitch-stamp-teal cursor-pointer"
                    :style="{ transform: 'rotate(0deg)' }"
                    :class="filters.type === type ? 'snitch-stamp-active' : ''"
                    @click="applyFilter('type', filters.type === type ? null : type)"
                >
                    {{ type }}
                </button>
            </div>

            <div
                class="snitch-contact-sheet snitch-contact-reveal mt-8 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
            >
                <Link
                    v-for="post in posts.data"
                    :key="post.id"
                    :href="feedShow.url(post.id)"
                    class="snitch-contact-cell group block"
                >
                    <div class="aspect-[3/4] overflow-hidden">
                        <img
                            v-if="post.media_url"
                            :src="post.media_url"
                            alt=""
                            class="h-full w-full object-cover contrast-[0.92] saturate-[0.8] transition duration-300 group-hover:scale-[1.03]"
                        />
                        <div
                            v-else
                            class="flex h-full items-center justify-center text-xs text-snitch-paper/50"
                        >
                            No media
                        </div>
                    </div>
                    <div class="relative z-10 space-y-1 p-3">
                        <p class="text-[10px] uppercase tracking-[0.14em] text-snitch-paper/55">
                            {{ post.platform }} · {{ post.type }}
                        </p>
                        <p class="snitch-annotation text-base leading-tight text-snitch-spot">
                            @{{ post.tracked_account?.handle }}
                        </p>
                        <p
                            v-if="post.analysis?.hook"
                            class="line-clamp-2 text-xs text-snitch-paper/80"
                        >
                            {{ post.analysis.hook }}
                        </p>
                        <p
                            v-else-if="post.analysis?.status === 'pending' || post.analysis?.status === 'processing'"
                            class="text-xs text-snitch-paper/45"
                        >
                            Analysis pending…
                        </p>
                    </div>
                </Link>
            </div>

            <div
                v-if="!posts.data.length"
                class="snitch-scrap relative mt-8 p-10 text-center"
            >
                <span class="snitch-tape left-8 -top-2" aria-hidden="true" />
                <p class="snitch-display text-2xl">No frames yet</p>
                <p class="mt-2 text-sm text-snitch-ink/65">
                    Add competitors and sync to fill the contact sheet.
                </p>
            </div>
        </div>
    </div>
</template>
