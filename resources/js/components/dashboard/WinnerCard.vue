<script setup lang="ts">
import SnitchImage from '@/components/SnitchImage.vue';

withDefaults(
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
</script>

<template>
    <a
        :href="post.url || undefined"
        :target="post.url ? '_blank' : undefined"
        rel="noreferrer"
        class="flex flex-col overflow-hidden rounded-md border border-slate-200 bg-white"
    >
        <div
            class="relative w-full overflow-hidden bg-slate-100"
            :class="compact ? 'h-[88px]' : 'h-[112px]'"
        >
            <SnitchImage
                v-if="post.thumbnail_url"
                :src="post.thumbnail_url"
                alt=""
                class="size-full"
                img-class="size-full object-cover"
                aspect-ratio="1 / 1"
            />
            <div v-else class="flex size-full items-center justify-center text-[9px] text-slate-400">No img</div>
            <span class="absolute top-0.5 right-0.5 rounded bg-slate-900/90 px-1 text-[9px] font-semibold text-white">
                {{ post.pi.toFixed(1) }}×
            </span>
        </div>
        <div class="space-y-0.5 px-1 py-1">
            <div class="break-words text-[10px] font-medium leading-snug text-slate-900">
                {{ post.is_own_account ? 'You' : `@${post.handle}` }}
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
    </a>
</template>
