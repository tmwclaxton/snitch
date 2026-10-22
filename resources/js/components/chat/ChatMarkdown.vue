<script setup lang="ts">
import { computed } from 'vue';
import ChatPostCard from '@/components/chat/ChatPostCard.vue';
import { renderMarkdown } from '@/lib/markdown';

type ChatPost = {
    url: string;
    handle?: string | null;
    caption?: string | null;
    thumbnail_url?: string | null;
    likes?: number | null;
    comments?: number | null;
    times_usual_label?: string | null;
};

const props = defineProps<{
    source: string;
    posts?: ChatPost[];
}>();

const INSTAGRAM_URL =
    /https?:\/\/(?:www\.)?instagram\.com\/(?:reel|p|tv)\/[A-Za-z0-9_-]+\/?(?:\?[^\s)]*)?/gi;

type Segment =
    | { type: 'html'; html: string }
    | { type: 'post'; post: ChatPost; url: string };

const segments = computed((): Segment[] => {
    const text = props.source ?? '';
    const byUrl = new Map(
        (props.posts ?? []).map((post) => [normalizeUrl(post.url), post] as const),
    );
    const used = new Set<string>();
    const parts: Segment[] = [];
    let lastIndex = 0;
    const matches = [...text.matchAll(INSTAGRAM_URL)];

    for (const match of matches) {
        const url = match[0];
        const index = match.index ?? 0;

        if (index > lastIndex) {
            const chunk = text.slice(lastIndex, index).trim();

            if (chunk !== '') {
                parts.push({
                    type: 'html',
                    html: renderMarkdown(chunk, { linkHandles: true }),
                });
            }
        }

        const key = normalizeUrl(url);
        const post = byUrl.get(key) ?? {
            url,
            handle: null,
            caption: null,
            thumbnail_url: null,
            likes: null,
            comments: null,
            times_usual_label: null,
        };
        used.add(key);
        parts.push({ type: 'post', post, url });
        lastIndex = index + url.length;
    }

    const tail = text.slice(lastIndex).trim();

    if (tail !== '') {
        parts.push({
            type: 'html',
            html: renderMarkdown(tail, { linkHandles: true }),
        });
    }

    if (parts.length === 0 && text.trim() !== '') {
        parts.push({
            type: 'html',
            html: renderMarkdown(text, { linkHandles: true }),
        });
    }

    for (const post of props.posts ?? []) {
        const key = normalizeUrl(post.url);

        if (used.has(key)) {
            continue;
        }

        used.add(key);
        parts.push({ type: 'post', post, url: post.url });
    }

    return parts;
});

function normalizeUrl(url: string): string {
    return url.replace(/\/?(\?.*)?$/u, '').toLowerCase();
}
</script>

<template>
    <div class="snitch-chat-md space-y-1 text-[15px] leading-relaxed text-snitch-ink">
        <template
            v-for="(segment, index) in segments"
            :key="index"
        >
            <div
                v-if="segment.type === 'html'"
                class="snitch-md snitch-chat-md-body"
                v-html="segment.html"
            />
            <ChatPostCard
                v-else
                :post="segment.post"
            />
        </template>
    </div>
</template>
