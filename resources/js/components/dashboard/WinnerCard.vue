<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { computed, ref } from 'vue';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import SnitchImage from '@/components/SnitchImage.vue';

const props = withDefaults(
    defineProps<{
        post: {
            id: number;
            handle: string | null;
            avatar?: string | null;
            tracked_account_id?: number | null;
            is_own_account: boolean;
            format: string;
            posted_at: string | null;
            pi: number;
            early: boolean;
            likes: number | null;
            likes_hidden?: boolean;
            comments: number;
            views: number | null;
            hook: string;
            tags: string[];
            thumbnail_url: string | null;
            url: string | null;
        };
        compact?: boolean;
    }>(),
    { compact: false },
);

const fmt = new Intl.NumberFormat('en-GB');
const CAPTION_COLLAPSE_CHARS = 96;
const captionExpanded = ref(false);

const captionSource = computed(() => props.post.hook?.trim() || 'No caption');

const captionNeedsToggle = computed(
    () => captionSource.value.length > CAPTION_COLLAPSE_CHARS,
);

const captionText = computed(() => {
    const text = captionSource.value;

    if (captionExpanded.value || ! captionNeedsToggle.value) {
        return text;
    }

    const slice = text.slice(0, CAPTION_COLLAPSE_CHARS);
    const lastSpace = slice.lastIndexOf(' ');

    return lastSpace > 40 ? slice.slice(0, lastSpace) : slice;
});

function handleLabel(): string {
    return props.post.is_own_account ? 'You' : `@${props.post.handle}`;
}

function openTracker(event: Event): void {
    event.preventDefault();
    event.stopPropagation();

    if (! props.post.tracked_account_id) {
        return;
    }

    router.visit(competitorShow.url(props.post.tracked_account_id));
}

function openInstagram(event: Event): void {
    event.preventDefault();
    event.stopPropagation();

    if (! props.post.url) {
        return;
    }

    window.open(props.post.url, '_blank', 'noopener,noreferrer');
}

function toggleCaption(event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    captionExpanded.value = ! captionExpanded.value;
}
</script>

<template>
    <Link
        :href="feedShow.url(post.id)"
        class="flex h-full flex-col overflow-hidden rounded-md border border-slate-200 bg-white transition hover:border-slate-400 hover:shadow-sm"
    >
        <div class="relative aspect-[4/5] w-full overflow-hidden bg-slate-100">
            <SnitchImage
                :src="post.thumbnail_url"
                alt=""
                class="size-full"
                img-class="size-full object-cover"
                aspect-ratio="4 / 5"
                fallback="paper"
            />
            <span
                class="absolute left-1 top-1 rounded bg-white/95 px-1 py-0.5 text-xs font-semibold uppercase tracking-wide text-slate-700"
            >
                {{ post.format }}
            </span>
        </div>

        <div class="flex flex-1 flex-col gap-1 px-1.5 py-1.5">
            <div class="flex min-w-0 items-start gap-1">
                <SnitchAvatar
                    :src="post.avatar"
                    :handle="post.handle"
                    size="sm"
                    class="!size-4 shrink-0"
                />
                <div class="min-w-0">
                    <div class="break-words text-xs font-medium leading-snug text-slate-900">
                        <span
                            v-if="post.tracked_account_id"
                            role="link"
                            tabindex="0"
                            class="cursor-pointer hover:underline"
                            @click="openTracker"
                            @keydown.enter="openTracker"
                        >
                            {{ handleLabel() }}
                        </span>
                        <template v-else>
                            {{ handleLabel() }}
                        </template>
                    </div>
                    <div
                        v-if="post.posted_at"
                        class="text-xs leading-snug text-slate-400"
                    >
                        {{ post.posted_at }}
                    </div>
                </div>
            </div>

            <div class="text-sm font-semibold leading-snug text-slate-900">
                {{ post.pi.toFixed(1) }}× their usual
                <span
                    v-if="post.early"
                    class="ml-0.5 inline-block rounded bg-amber-50 px-1 py-px text-xs font-medium text-amber-700"
                >
                    early estimate
                </span>
            </div>

            <div class="min-w-0">
                <p class="break-words text-sm leading-snug text-slate-600">
                    {{ captionText }}
                </p>
                <button
                    v-if="captionNeedsToggle"
                    type="button"
                    class="mt-0.5 text-xs font-medium text-slate-500 underline-offset-2 hover:text-slate-800 hover:underline"
                    @click="toggleCaption"
                >
                    {{ captionExpanded ? 'less' : 'more' }}
                </button>
            </div>

            <div class="flex flex-wrap gap-x-1.5 gap-y-0.5 text-xs tabular-nums leading-snug text-slate-500">
                <span v-if="post.likes_hidden" class="rounded bg-amber-50 px-1 text-amber-700">likes hidden</span>
                <span v-else-if="post.likes != null">{{ fmt.format(post.likes) }} likes</span>
                <span>{{ fmt.format(post.comments) }} comments</span>
                <span v-if="post.views != null">{{ fmt.format(post.views) }} plays</span>
            </div>

            <div
                v-if="post.tags?.length"
                class="flex flex-wrap gap-0.5"
            >
                <span
                    v-for="tag in post.tags"
                    :key="tag"
                    class="rounded bg-slate-100 px-1 py-px text-xs text-slate-600"
                >
                    {{ tag }}
                </span>
            </div>

            <button
                v-if="post.url"
                type="button"
                class="mt-auto inline-flex items-center gap-0.5 text-xs font-medium text-slate-700 underline-offset-2 hover:underline"
                @click="openInstagram"
            >
                Open on Instagram
                <ExternalLink class="h-2.5 w-2.5" />
            </button>
        </div>
    </Link>
</template>
