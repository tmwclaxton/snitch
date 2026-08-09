<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays, Eye } from '@lucide/vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { index as blogIndex, show } from '@/routes/blog';

interface Source {
    title: string;
    url: string;
    description?: string;
}

interface BlogPost {
    id: number;
    title: string;
    slug: string;
    excerpt: string;
    body_html: string;
    image_url?: string | null;
    tags: string[];
    sources: Source[];
    published_at: string;
    view_count?: number;
    url?: string;
}

interface BlogPostCard {
    id: number;
    title: string;
    slug: string;
    excerpt: string;
    image_url?: string | null;
    tags: string[];
    published_at: string;
}

const props = withDefaults(
    defineProps<{
        post: BlogPost;
        share_links: Record<string, string>;
        more_posts?: BlogPostCard[];
    }>(),
    {
        more_posts: () => [],
    },
);

defineOptions({
    layout: PublicLayout,
});

const shareItems = [
    { key: 'facebook', label: 'Facebook' },
    { key: 'twitter', label: 'X' },
    { key: 'linkedin', label: 'LinkedIn' },
    { key: 'whatsapp', label: 'WhatsApp' },
] as const;

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString('en-GB', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

function shareHref(key: string): string {
    return props.share_links[key] ?? '#';
}
</script>

<template>
    <div class="px-5 py-14 sm:px-8 sm:py-20">
        <div class="mx-auto max-w-3xl">
            <Link
                :href="blogIndex()"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-snitch-ink/70 hover:text-snitch-ink"
                prefetch
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                Back to blog
            </Link>

            <article class="snitch-doc relative mt-6 px-6 py-10 sm:px-10">
                <div
                    v-if="post.tags.length > 0"
                    class="relative z-10 flex flex-wrap gap-2"
                >
                    <span
                        v-for="tag in post.tags.slice(0, 5)"
                        :key="tag"
                        class="snitch-ink-label text-[0.65rem]"
                    >
                        {{ tag }}
                    </span>
                </div>

                <h1
                    class="snitch-display relative z-10 mt-4 text-3xl text-snitch-ink sm:text-4xl"
                >
                    {{ post.title }}
                </h1>

                <p
                    class="relative z-10 mt-4 text-lg leading-relaxed text-snitch-ink/80"
                >
                    {{ post.excerpt }}
                </p>

                <div
                    class="relative z-10 mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-snitch-ink/60"
                >
                    <span class="inline-flex items-center gap-1.5">
                        <CalendarDays class="size-4" aria-hidden="true" />
                        <time :datetime="post.published_at">{{
                            formatDate(post.published_at)
                        }}</time>
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <Eye class="size-4" aria-hidden="true" />
                        {{ post.view_count ?? 0 }} views
                    </span>
                </div>

                <img
                    v-if="post.image_url"
                    :src="post.image_url"
                    :alt="post.title"
                    class="relative z-10 mt-8 w-full border-2 border-snitch-ink/20 object-cover"
                />

                <div
                    class="snitch-blog-prose relative z-10 mt-8 text-base leading-relaxed text-snitch-ink/85"
                    v-html="post.body_html"
                />
            </article>

            <div
                v-if="post.sources.length > 0"
                class="snitch-scrap relative mt-8 p-6 sm:p-8"
            >
                <h2 class="snitch-ink-label">Sources</h2>
                <ul class="relative z-10 mt-4 space-y-3">
                    <li v-for="source in post.sources" :key="source.url">
                        <a
                            :href="source.url"
                            class="font-medium text-snitch-ink underline decoration-snitch-spot/60 underline-offset-2 hover:decoration-snitch-spot"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ source.title || source.url }}
                        </a>
                        <p
                            v-if="source.description"
                            class="mt-0.5 text-sm text-snitch-ink/70"
                        >
                            {{ source.description }}
                        </p>
                    </li>
                </ul>
            </div>

            <div class="mt-8 flex flex-wrap gap-2">
                <a
                    v-for="item in shareItems"
                    :key="item.key"
                    :href="shareHref(item.key)"
                    class="snitch-btn snitch-btn-ghost text-sm"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <span class="relative z-10">{{ item.label }}</span>
                </a>
            </div>

            <section v-if="more_posts.length > 0" class="mt-14">
                <h2 class="snitch-display text-2xl text-snitch-ink">
                    More on the board
                </h2>
                <div class="mt-6 grid gap-4 sm:grid-cols-3">
                    <Link
                        v-for="related in more_posts"
                        :key="related.id"
                        :href="show(related.slug)"
                        class="snitch-scrap relative block p-4 transition hover:-translate-y-0.5"
                        prefetch
                    >
                        <h3
                            class="snitch-display relative z-10 text-lg text-snitch-ink"
                        >
                            {{ related.title }}
                        </h3>
                        <p
                            class="relative z-10 mt-2 line-clamp-3 text-sm text-snitch-ink/70"
                        >
                            {{ related.excerpt }}
                        </p>
                    </Link>
                </div>
            </section>
        </div>
    </div>
</template>
