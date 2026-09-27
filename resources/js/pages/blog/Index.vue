<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays, Eye } from '@lucide/vue';
import SnitchImage from '@/components/SnitchImage.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { show } from '@/routes/blog';

interface BlogPost {
    id: number;
    title: string;
    slug: string;
    excerpt: string;
    image_url?: string | null;
    tags: string[];
    published_at: string;
    view_count?: number;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

defineProps<{
    posts: {
        data: BlogPost[];
        links: PaginationLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
}>();

defineOptions({
    layout: PublicLayout,
});

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-GB', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}
</script>

<template>
    <div class="px-4 py-14 sm:px-8 sm:py-20">
        <div class="mx-auto max-w-6xl">
            <header>
                <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Blog</p>
                <h1 class="mt-2 text-pretty text-4xl font-semibold tracking-tight text-neutral-950 sm:text-5xl">
                    Instagram competitor notes worth keeping.
                </h1>
                <p class="mt-4 max-w-2xl text-base leading-relaxed text-neutral-600">
                    Hooks, remakes, and tracking workflows for brands and
                    agencies who watch rivals on Instagram.
                </p>
            </header>

            <div
                v-if="posts.data.length > 0"
                class="mt-10 grid gap-6 sm:grid-cols-2"
            >
                <Link
                    v-for="post in posts.data"
                    :key="post.id"
                    :href="show(post.slug)"
                    class="group flex flex-col overflow-hidden border border-neutral-200 bg-white shadow-[0_20px_50px_-15px_rgba(0,0,0,0.10)] transition hover:-translate-y-0.5"
                    prefetch
                >
                    <SnitchImage
                        :src="post.image_url"
                        :alt="post.title"
                        aspect-ratio="16 / 10"
                        class="block w-full"
                        img-class="h-full w-full object-cover"
                        fallback="paper"
                    />
                    <div class="space-y-3 p-5 sm:p-6">
                        <div
                            v-if="post.tags.length > 0"
                            class="flex flex-wrap gap-2"
                        >
                            <span
                                v-for="tag in post.tags.slice(0, 3)"
                                :key="tag"
                                class="text-[0.65rem] font-medium uppercase tracking-wide text-neutral-500"
                            >
                                {{ tag }}
                            </span>
                        </div>
                        <h2 class="text-xl font-semibold tracking-tight text-neutral-950 group-hover:underline sm:text-2xl">
                            {{ post.title }}
                        </h2>
                        <p class="text-sm leading-relaxed text-neutral-600">
                            {{ post.excerpt }}
                        </p>
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-neutral-500"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <CalendarDays
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                <time :datetime="post.published_at">{{
                                    formatDate(post.published_at)
                                }}</time>
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <Eye class="size-3.5" aria-hidden="true" />
                                {{ post.view_count ?? 0 }} views
                            </span>
                        </div>
                    </div>
                </Link>
            </div>

            <div
                v-else
                class="mx-auto mt-10 max-w-xl border border-neutral-200 bg-neutral-50 p-8 text-center"
            >
                <p class="text-2xl font-semibold tracking-tight text-neutral-950">
                    No posts yet.
                </p>
                <p class="mt-2 text-sm text-neutral-600">
                    Check back soon for Instagram competitor tracking notes and remake
                    ideas.
                </p>
            </div>

            <nav
                v-if="posts.last_page > 1"
                class="mt-10 flex flex-wrap justify-center gap-2"
                aria-label="Blog pagination"
            >
                <template v-for="link in posts.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="inline-flex px-3 py-2 text-sm font-medium"
                        :class="
                            link.active
                                ? 'bg-[#F0C400] text-neutral-950'
                                : 'border border-neutral-200 text-neutral-700 hover:border-neutral-400'
                        "
                        prefetch
                    >
                        <span v-html="link.label" />
                    </Link>
                    <span
                        v-else
                        class="inline-flex pointer-events-none border border-neutral-200 px-3 py-2 text-sm font-medium text-neutral-400 opacity-40"
                    >
                        <span v-html="link.label" />
                    </span>
                </template>
            </nav>
        </div>
    </div>
</template>
