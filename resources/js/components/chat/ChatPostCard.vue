<script setup lang="ts">
defineProps<{
    post: {
        url: string;
        handle?: string | null;
        caption?: string | null;
        thumbnail_url?: string | null;
        likes?: number | null;
        comments?: number | null;
        times_usual_label?: string | null;
    };
}>();
</script>

<template>
    <a
        :href="post.url"
        target="_blank"
        rel="noopener noreferrer"
        class="my-3 flex overflow-hidden rounded-lg border border-white/10 bg-[#141416] transition hover:border-[#FCD700]/40"
    >
        <div class="h-24 w-24 shrink-0 bg-[#1a1a1d]">
            <img
                v-if="post.thumbnail_url"
                :src="post.thumbnail_url"
                alt=""
                class="size-full object-cover"
                loading="lazy"
            >
            <div
                v-else
                class="flex size-full items-center justify-center font-mono text-xs uppercase tracking-wide text-[#EDEAE2]/40"
            >
                Post
            </div>
        </div>
        <div class="flex min-w-0 flex-1 flex-col justify-center gap-1 p-3 text-[15px] leading-relaxed">
            <p
                v-if="post.handle"
                class="font-medium text-[#EDEAE2]"
            >
                @{{ post.handle }}
            </p>
            <p
                v-if="post.times_usual_label"
                class="text-[#EDEAE2]/80"
            >
                {{ post.times_usual_label }}
            </p>
            <p
                v-if="post.caption"
                class="line-clamp-2 text-[#EDEAE2]/70"
            >
                {{ post.caption }}
            </p>
            <p class="text-sm text-[#EDEAE2]/55">
                <span v-if="post.likes != null">Likes {{ post.likes }}</span>
                <span
                    v-if="post.likes != null && post.comments != null"
                    class="mx-1"
                >·</span>
                <span v-if="post.comments != null">Comments {{ post.comments }}</span>
                <span
                    v-if="post.likes == null && post.comments == null"
                >Open on Instagram</span>
            </p>
        </div>
    </a>
</template>
