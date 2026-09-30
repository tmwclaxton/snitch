<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays, Eye } from '@lucide/vue';
import SnitchImage from '@/components/SnitchImage.vue';
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
    <div class="px-4 py-14 sm:px-8 sm:py-20">
        <div class="mx-auto max-w-3xl">
            <Link
                :href="blogIndex()"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-snitch-caution-fog/75 hover:text-snitch-caution-yellow"
                prefetch
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                Back to blog
            </Link>

            <article class="mt-6">
                <div
                    v-if="post.tags.length > 0"
                    class="flex flex-wrap gap-2"
                >
                    <span
                        v-for="tag in post.tags.slice(0, 5)"
                        :key="tag"
                        class="font-mono text-sm uppercase tracking-wide text-snitch-caution-fog/70"
                    >
                        {{ tag }}
                    </span>
                </div>

                <h1 class="mt-4 text-3xl font-semibold tracking-tight text-snitch-caution-fog sm:text-4xl">
                    {{ post.title }}
                </h1>

                <p class="mt-4 text-lg leading-relaxed text-snitch-caution-fog/80">
                    {{ post.excerpt }}
                </p>

                <div
                    class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-snitch-caution-fog/70"
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

                <SnitchImage
                    v-if="post.image_url"
                    :src="post.image_url"
                    :alt="post.title"
                    aspect-ratio="16 / 9"
                    loading="eager"
                    class="mt-8 block w-full border border-white/10"
                    img-class="w-full object-cover"
                    fallback="paper"
                />

                <div
                    class="snitch-blog-prose mt-8 text-base leading-relaxed text-snitch-caution-fog/85"
                    v-html="post.body_html"
                />
            </article>

            <div
                v-if="post.sources.length > 0"
                class="mt-8 border border-white/10 bg-[#141416] p-6 sm:p-8"
            >
                <h2 class="font-mono text-sm uppercase tracking-wide text-snitch-caution-fog/70">Sources</h2>
                <ul class="mt-4 space-y-3">
                    <li v-for="source in post.sources" :key="source.url">
                        <a
                            :href="source.url"
                            class="font-medium text-snitch-caution-fog underline decoration-snitch-caution-yellow/70 underline-offset-2 hover:decoration-snitch-caution-yellow"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ source.title || source.url }}
                        </a>
                        <p
                            v-if="source.description"
                            class="mt-0.5 text-sm text-snitch-caution-fog/75"
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
                    class="inline-flex border border-white/20 px-3 py-2 text-sm font-medium text-snitch-caution-fog/85 hover:border-snitch-caution-yellow hover:text-snitch-caution-fog"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    {{ item.label }}
                </a>
            </div>

            <section v-if="more_posts.length > 0" class="mt-14">
                <h2 class="text-2xl font-semibold tracking-tight text-snitch-caution-fog">
                    More posts
                </h2>
                <div class="mt-6 grid gap-4 sm:grid-cols-3">
                    <Link
                        v-for="related in more_posts"
                        :key="related.id"
                        :href="show(related.slug)"
                        class="block border border-white/10 bg-[#141416] p-4 transition hover:-translate-y-0.5 hover:border-snitch-caution-yellow/60"
                        prefetch
                    >
                        <h3 class="text-lg font-semibold tracking-tight text-snitch-caution-fog">
                            {{ related.title }}
                        </h3>
                        <p class="mt-2 text-sm text-snitch-caution-fog/75">
                            {{ related.excerpt }}
                        </p>
                    </Link>
                </div>
            </section>
        </div>
    </div>
</template>
