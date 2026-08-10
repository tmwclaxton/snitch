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
    <div class="px-5 py-14 sm:px-8 sm:py-20">
        <div class="mx-auto max-w-6xl">
            <header>
                <p class="snitch-ink-label">Blog</p>
                <h1
                    class="snitch-display relative z-10 mt-2 text-pretty text-4xl text-snitch-ink sm:text-5xl"
                >
                    Competitor social notes worth keeping.
                </h1>
                <p
                    class="mt-4 max-w-2xl text-base leading-relaxed text-snitch-ink/80"
                >
                    Hooks, remakes, and tracking workflows for brands and
                    agencies who watch rivals across TikTok, Instagram, YouTube,
                    and more.
                </p>
            </header>

            <div
                v-if="posts.data.length > 0"
                class="snitch-contact-sheet mt-10 grid gap-6 sm:grid-cols-2"
            >
                <Link
                    v-for="post in posts.data"
                    :key="post.id"
                    :href="show(post.slug)"
                    class="snitch-scrap group relative flex flex-col overflow-hidden p-0 transition hover:-translate-y-0.5"
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
                    <div class="relative z-10 space-y-3 p-5 sm:p-6">
                        <div
                            v-if="post.tags.length > 0"
                            class="flex flex-wrap gap-2"
                        >
                            <span
                                v-for="tag in post.tags.slice(0, 3)"
                                :key="tag"
                                class="snitch-ink-label text-[0.65rem]"
                            >
                                {{ tag }}
                            </span>
                        </div>
                        <h2
                            class="snitch-display text-xl text-snitch-ink group-hover:underline sm:text-2xl"
                        >
                            {{ post.title }}
                        </h2>
                        <p class="text-sm leading-relaxed text-snitch-ink/75">
                            {{ post.excerpt }}
                        </p>
                        <div
                            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-snitch-ink/60"
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
                class="snitch-scrap relative mx-auto mt-10 max-w-xl p-8 text-center"
            >
                <p class="snitch-display text-2xl text-snitch-ink">
                    No posts on the board yet.
                </p>
                <p class="mt-2 text-sm text-snitch-ink/70">
                    Check back soon for competitor tracking notes and remake
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
                        class="snitch-btn"
                        :class="
                            link.active
                                ? 'snitch-btn-spot'
                                : 'snitch-btn-ghost'
                        "
                        prefetch
                    >
                        <span
                            class="relative z-10"
                            v-html="link.label"
                        />
                    </Link>
                    <span
                        v-else
                        class="snitch-btn snitch-btn-ghost pointer-events-none opacity-40"
                    >
                        <span
                            class="relative z-10"
                            v-html="link.label"
                        />
                    </span>
                </template>
            </nav>
        </div>
    </div>
</template>
