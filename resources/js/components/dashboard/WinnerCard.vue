<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import SnitchImage from '@/components/SnitchImage.vue';

const props = withDefaults(
    defineProps<{
        post: {
            id: number;
            handle: string | null;
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
</script>

<template>
    <Link
        :href="feedShow.url(post.id)"
        class="flex flex-col overflow-hidden rounded-md border border-slate-200 bg-white transition hover:border-slate-400 hover:shadow-sm"
    >
        <div
            class="relative w-full overflow-hidden bg-slate-100"
            :class="compact ? 'h-[88px]' : 'h-[112px]'"
        >
            <SnitchImage
                :src="post.thumbnail_url"
                alt=""
                class="size-full"
                img-class="size-full object-cover"
                aspect-ratio="1 / 1"
                fallback="paper"
            />
            <span class="absolute top-0.5 right-0.5 rounded bg-slate-900/90 px-1 text-[9px] font-semibold text-white">
                {{ post.pi.toFixed(1) }}×
            </span>
        </div>
        <div class="space-y-0.5 px-1 py-1">
            <div class="break-words text-[10px] font-medium leading-snug text-slate-900">
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
            <div class="text-[10px] leading-snug text-slate-400">
                {{ post.format }}<span v-if="post.posted_at"> · {{ post.posted_at }}</span>
            </div>
            <div class="text-[10px] tabular-nums leading-snug text-slate-500">
                <span v-if="post.likes_hidden" class="rounded bg-amber-50 px-1 text-amber-700">hidden</span>
                <span v-else-if="post.likes != null">{{ fmt.format(post.likes) }}♥</span>
                <span v-else>-</span>
                · {{ fmt.format(post.comments) }}💬
            </div>
            <p class="line-clamp-2 text-[10px] leading-snug text-slate-600">{{ post.hook || 'No caption' }}</p>
        </div>
    </Link>
</template>
