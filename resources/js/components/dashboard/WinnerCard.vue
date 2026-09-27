<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import SnitchAvatar from '@/components/SnitchAvatar.vue';
import SnitchImage from '@/components/SnitchImage.vue';

defineProps<{
    post: {
        id: number;
        handle: string | null;
        is_own_account: boolean;
        format: string;
        posted_at: string | null;
        pi: number;
        early: boolean;
        likes: number | null;
        comments: number;
        views: number | null;
        hook: string;
        tags: string[];
        thumbnail_url: string | null;
        url: string | null;
    };
}>();

const fmt = new Intl.NumberFormat('en-GB');
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="relative aspect-square bg-slate-100">
            <SnitchImage
                v-if="post.thumbnail_url"
                :src="post.thumbnail_url"
                alt=""
                class="size-full"
                img-class="size-full object-cover"
                aspect-ratio="1 / 1"
            />
            <div v-else class="flex size-full items-center justify-center text-xs text-slate-400">No image</div>
            <span class="absolute left-2 top-2 rounded bg-white/95 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-700">
                {{ post.format }}
            </span>
        </div>
        <div class="flex flex-1 flex-col gap-2 p-3">
            <div class="flex items-center gap-2">
                <SnitchAvatar :handle="post.handle" size="sm" />
                <div class="min-w-0">
                    <div class="truncate text-sm font-medium text-slate-900">@{{ post.handle }}</div>
                    <div class="text-[11px] text-slate-500">{{ post.posted_at || '—' }}</div>
                </div>
            </div>
            <div class="text-lg font-semibold text-slate-900">
                {{ post.pi.toFixed(1) }}× their usual
                <span
                    v-if="post.early"
                    class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700"
                >
                    early estimate
                </span>
            </div>
            <p class="line-clamp-2 text-sm text-slate-600">{{ post.hook || 'No caption' }}</p>
            <div class="mt-auto flex flex-wrap gap-x-3 gap-y-1 text-xs tabular-nums text-slate-500">
                <span v-if="post.likes != null">{{ fmt.format(post.likes) }} likes</span>
                <span>{{ fmt.format(post.comments) }} comments</span>
                <span v-if="post.views != null">{{ fmt.format(post.views) }} plays</span>
            </div>
            <div v-if="post.tags.length" class="flex flex-wrap gap-1">
                <span
                    v-for="tag in post.tags"
                    :key="tag"
                    class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600"
                >
                    {{ tag }}
                </span>
            </div>
            <a
                v-if="post.url"
                :href="post.url"
                target="_blank"
                rel="noreferrer"
                class="inline-flex items-center gap-1 text-xs font-medium text-slate-700 underline-offset-2 hover:underline"
            >
                Open on Instagram <ExternalLink class="h-3 w-3" />
            </a>
        </div>
    </article>
</template>
