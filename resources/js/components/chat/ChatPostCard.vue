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
        class="my-3 flex overflow-hidden rounded-lg border border-snitch-ink/10 bg-snitch-lift transition hover:border-snitch-spot/50"
    >
        <div class="h-24 w-24 shrink-0 bg-snitch-fog">
            <img
                v-if="post.thumbnail_url"
                :src="post.thumbnail_url"
                alt=""
                class="size-full object-cover"
                loading="lazy"
            >
            <div
                v-else
                class="flex size-full items-center justify-center font-mono text-xs uppercase tracking-wide text-snitch-ink/40"
            >
                Post
            </div>
        </div>
        <div class="flex min-w-0 flex-1 flex-col justify-center gap-1 p-3 text-[15px] leading-relaxed">
            <p
                v-if="post.handle"
                class="font-medium text-snitch-ink"
            >
                @{{ post.handle }}
            </p>
            <p
                v-if="post.times_usual_label"
                class="text-snitch-ink/80"
            >
                {{ post.times_usual_label }}
            </p>
            <p
                v-if="post.caption"
                class="line-clamp-2 text-snitch-ink/70"
            >
                {{ post.caption }}
            </p>
            <p class="text-sm text-snitch-ink/55">
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
