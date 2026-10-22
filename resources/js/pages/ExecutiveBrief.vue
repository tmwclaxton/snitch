<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatAppDate } from '@/lib/dates';
import { index as chatIndex } from '@/routes/chat';

defineOptions({
    layout: AppLayout,
});

type BriefPost = {
    post_id: number | null;
    url: string | null;
    snitch_url: string | null;
    handle: string | null;
    format: string | null;
    posted_label: string | null;
    hook: string | null;
    caption: string | null;
    times_usual: number | null;
    times_usual_label: string | null;
    thumbnail_url: string | null;
};

type OwnBullet = {
    text: string;
    posts: BriefPost[];
};

type CompetitorItem = {
    text: string;
    handle: string | null;
    posts: BriefPost[];
};

type Suggestion = {
    number: number;
    title: string;
    why: string;
    evidence: BriefPost[];
};

type Freshness = {
    handle: string | null;
    last_synced_at: string | null;
    label: string;
};

type Brief = {
    empty: boolean;
    empty_reason: string | null;
    brief_date: string | null;
    generated_at: string | null;
    headline: string | null;
    summary: string | null;
    own_activity: OwnBullet[];
    competitor_activity: CompetitorItem[];
    suggestions: Suggestion[];
    freshness: Freshness[];
    chat_url: string;
    competitors_url: string;
    curator_overrides: {
        summary: boolean;
        suggestions: boolean;
        updated_at: string | null;
    };
    insufficient_data: boolean;
};

const props = defineProps<{
    brief: Brief;
}>();

const dateLabel = computed(() => {
    if (! props.brief.brief_date) {
        return null;
    }

    return formatAppDate(props.brief.brief_date);
});

function postHref(post: BriefPost): string | null {
    return post.snitch_url || post.url || null;
}

function competitorTextWithoutHandle(item: CompetitorItem): string {
    if (! item.handle) {
        return item.text;
    }

    const prefix = '@' + item.handle;

    if (item.text.startsWith(prefix)) {
        return item.text.slice(prefix.length);
    }

    return ' ' + item.text;
}
</script>

<template>
    <div>
        <Head title="Executive Brief" />

        <div class="flex flex-1 flex-col px-4 py-6 md:px-6 md:py-8">
            <article
                class="mx-auto w-full max-w-[52.5rem] space-y-8 text-[15px] leading-relaxed text-snitch-ink"
            >
                <header class="space-y-3 border-b border-snitch-ink/10 pb-6">
                    <p class="font-mono text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                        Executive Brief
                    </p>
                    <h1 class="snitch-hero-display text-3xl text-snitch-ink sm:text-4xl">
                        <span class="snitch-highlight">Brief</span>
                    </h1>
                    <p
                        v-if="dateLabel"
                        class="text-sm text-snitch-ink/70"
                    >
                        Brief date: {{ dateLabel }}
                    </p>
                </header>

                <section
                    v-if="brief.empty"
                    class="rounded border border-snitch-ink/10 bg-snitch-lift p-6"
                >
                    <h2 class="snitch-hero-display text-xl text-snitch-ink">
                        Nothing to brief yet
                    </h2>
                    <p class="mt-3 text-[15px] leading-relaxed text-snitch-ink/80">
                        {{ brief.empty_reason }}
                    </p>
                    <Link
                        :href="competitors()"
                        class="snitch-btn snitch-btn-spot mt-5 inline-flex px-3 py-2 text-sm font-medium"
                    >
                        Go to Competitors
                    </Link>
                </section>

                <template v-else>
                    <section class="space-y-3">
                        <h2 class="font-mono text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                            Summary
                        </h2>
                        <p class="text-[15px] leading-relaxed text-snitch-ink">
                            {{ brief.summary || 'Not enough stored data for a summary yet.' }}
                        </p>
                    </section>

                    <section class="space-y-4">
                        <h2 class="font-mono text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                            What you've been doing
                        </h2>
                        <ul class="space-y-4">
                            <li
                                v-for="(bullet, index) in brief.own_activity"
                                :key="`own-${index}`"
                                class="space-y-3"
                            >
                                <p class="text-[15px] leading-relaxed text-snitch-ink">
                                    {{ bullet.text }}
                                </p>
                                <div
                                    v-if="bullet.posts.length > 0"
                                    class="flex flex-wrap gap-2"
                                >
                                    <a
                                        v-for="post in bullet.posts"
                                        :key="`own-post-${post.post_id}-${post.url}`"
                                        :href="postHref(post) || undefined"
                                        :target="post.url ? '_blank' : undefined"
                                        :rel="post.url ? 'noopener noreferrer' : undefined"
                                        class="group flex max-w-full items-start gap-2 rounded border border-snitch-ink/10 bg-snitch-lift p-2 no-underline"
                                    >
                                        <img
                                            v-if="post.thumbnail_url"
                                            :src="post.thumbnail_url"
                                            alt=""
                                            class="size-14 shrink-0 object-cover"
                                        />
                                        <span
                                            v-else
                                            class="flex size-14 shrink-0 items-center justify-center bg-snitch-fog font-mono text-xs uppercase text-snitch-ink/55"
                                        >
                                            {{ post.format || 'Post' }}
                                        </span>
                                        <span class="min-w-0 text-sm text-snitch-ink">
                                            <span class="font-medium">
                                                {{ post.format || 'Post' }}
                                            </span>
                                            <span
                                                v-if="post.posted_label"
                                                class="text-snitch-ink/60"
                                            >
                                                · {{ post.posted_label }}
                                            </span>
                                            <span
                                                v-if="post.times_usual_label"
                                                class="mt-0.5 block text-snitch-ink/80"
                                            >
                                                {{ post.times_usual_label }}
                                            </span>
                                        </span>
                                    </a>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <section class="space-y-4">
                        <h2 class="font-mono text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                            What competitors have been doing
                        </h2>
                        <ul class="space-y-5">
                            <li
                                v-for="(item, index) in brief.competitor_activity"
                                :key="`comp-${index}`"
                                class="space-y-3"
                            >
                                <p class="text-[15px] leading-relaxed text-snitch-ink">
                                    <template v-if="item.handle">
                                        <a
                                            :href="`https://www.instagram.com/${item.handle}/`"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="font-medium text-snitch-ink underline decoration-snitch-ink/30 underline-offset-2"
                                        >@{{ item.handle }}</a>{{ competitorTextWithoutHandle(item) }}
                                    </template>
                                    <template v-else>
                                        {{ item.text }}
                                    </template>
                                </p>
                                <div
                                    v-if="item.posts.length > 0"
                                    class="grid gap-2 sm:grid-cols-2"
                                >
                                    <a
                                        v-for="post in item.posts"
                                        :key="`comp-post-${post.post_id}-${post.url}`"
                                        :href="postHref(post) || undefined"
                                        :target="post.url ? '_blank' : undefined"
                                        :rel="post.url ? 'noopener noreferrer' : undefined"
                                        class="flex gap-3 rounded border border-snitch-ink/10 bg-snitch-lift p-3 no-underline"
                                    >
                                        <img
                                            v-if="post.thumbnail_url"
                                            :src="post.thumbnail_url"
                                            alt=""
                                            class="size-16 shrink-0 object-cover"
                                        />
                                        <span
                                            v-else
                                            class="flex size-16 shrink-0 items-center justify-center bg-snitch-fog font-mono text-xs uppercase text-snitch-ink/55"
                                        >
                                            {{ post.format || 'Post' }}
                                        </span>
                                        <span class="min-w-0 space-y-1 text-sm text-snitch-ink">
                                            <span class="block font-medium">
                                                @{{ post.handle || item.handle }}
                                            </span>
                                            <span class="block text-snitch-ink/70">
                                                {{ post.format || 'Post' }}
                                                <template v-if="post.posted_label">
                                                    · {{ post.posted_label }}
                                                </template>
                                            </span>
                                            <span
                                                v-if="post.times_usual_label"
                                                class="block text-snitch-ink/80"
                                            >
                                                {{ post.times_usual_label }}
                                            </span>
                                            <span
                                                v-if="post.hook || post.caption"
                                                class="block text-snitch-ink/70"
                                            >
                                                {{ post.hook || post.caption }}
                                            </span>
                                        </span>
                                    </a>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <section class="space-y-4">
                        <h2 class="font-mono text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
                            Our suggestions
                        </h2>
                        <ol class="space-y-5">
                            <li
                                v-for="suggestion in brief.suggestions"
                                :key="suggestion.number"
                                class="space-y-2"
                            >
                                <p class="text-[15px] font-medium leading-relaxed text-snitch-ink">
                                    {{ suggestion.number }}. {{ suggestion.title }}
                                </p>
                                <p class="text-[15px] leading-relaxed text-snitch-ink/85">
                                    <span class="font-medium text-snitch-ink">Why:</span>
                                    {{ suggestion.why }}
                                </p>
                                <div
                                    v-if="suggestion.evidence.length > 0"
                                    class="flex flex-wrap gap-2 pt-1"
                                >
                                    <a
                                        v-for="post in suggestion.evidence"
                                        :key="`ev-${suggestion.number}-${post.post_id}`"
                                        :href="postHref(post) || undefined"
                                        :target="post.url ? '_blank' : undefined"
                                        :rel="post.url ? 'noopener noreferrer' : undefined"
                                        class="flex items-center gap-2 rounded border border-snitch-ink/10 bg-snitch-lift px-2 py-1.5 text-sm text-snitch-ink no-underline"
                                    >
                                        <img
                                            v-if="post.thumbnail_url"
                                            :src="post.thumbnail_url"
                                            alt=""
                                            class="size-10 shrink-0 object-cover"
                                        />
                                        <span>
                                            <span class="font-medium">
                                                @{{ post.handle || 'post' }}
                                            </span>
                                            <span class="text-snitch-ink/65">
                                                · {{ post.format || 'Post' }}
                                                <template v-if="post.times_usual_label">
                                                    · {{ post.times_usual_label }}
                                                </template>
                                            </span>
                                        </span>
                                    </a>
                                </div>
                            </li>
                        </ol>
                    </section>

                    <footer class="space-y-3 border-t border-snitch-ink/10 pt-6 text-sm text-snitch-ink/70">
                        <p v-if="dateLabel">
                            Brief date: {{ dateLabel }}
                        </p>
                        <p v-if="brief.freshness.length > 0">
                            Freshness:
                            <span
                                v-for="(row, index) in brief.freshness"
                                :key="`fresh-${index}`"
                            >
                                <template v-if="index > 0">; </template>
                                <span v-if="row.handle">@{{ row.handle }}: </span>{{ row.label }}
                            </span>
                        </p>
                        <p>
                            Ask follow-ups in
                            <Link
                                :href="chatIndex()"
                                class="font-medium text-snitch-ink underline decoration-snitch-ink/35 underline-offset-2"
                            >
                                Chat
                            </Link>.
                        </p>
                    </footer>
                </template>
            </article>
        </div>
    </div>
</template>
