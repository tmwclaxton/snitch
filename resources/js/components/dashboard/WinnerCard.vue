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
        class="flex gap-1.5 overflow-hidden rounded-md border border-slate-200 bg-white p-1"
        :class="compact ? 'flex-col' : 'items-start'"
    >
        <div
            class="relative shrink-0 overflow-hidden rounded bg-slate-100"
            :class="compact ? 'aspect-square w-full' : 'h-14 w-14'"
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
            <span class="absolute bottom-0.5 right-0.5 rounded bg-slate-900/90 px-1 text-[9px] font-semibold text-white">
                {{ post.pi.toFixed(1) }}×
            </span>
        </div>
        <div class="min-w-0 flex-1 px-0.5 pb-0.5">
            <div class="truncate text-[10px] font-medium text-slate-900">
                {{ post.is_own_account ? 'You' : `@${post.handle}` }}
                <span class="font-normal text-slate-400">· {{ post.format }}</span>
            </div>
            <p v-if="!compact" class="mt-0.5 line-clamp-1 text-[10px] text-slate-600">{{ post.hook || 'No caption' }}</p>
            <div class="mt-0.5 text-[10px] tabular-nums text-slate-500">
                <span v-if="post.likes != null">{{ fmt.format(post.likes) }}♥</span>
                <span v-else>—</span>
                · {{ fmt.format(post.comments) }}💬
            </div>
        </div>
    </a>
</template>
