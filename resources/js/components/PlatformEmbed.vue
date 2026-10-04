<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import SnitchImage from '@/components/SnitchImage.vue';
import { isDisplayableImageSrc, isDurableMediaSrc } from '@/lib/mediaSrc';
import { openOnPlatformLabel } from '@/lib/platforms';

export type EmbedConfig = {
    provider: string;
    src: string;
    title: string;
    aspect: string;
};

const props = withDefaults(
    defineProps<{
        embed?: EmbedConfig | null;
        coverUrl?: string | null;
        mediaUrl?: string | null;
        postUrl?: string | null;
        platform?: string;
        compact?: boolean;
        interactive?: boolean;
        lazy?: boolean;
    }>(),
    {
        embed: null,
        coverUrl: null,
        mediaUrl: null,
        postUrl: null,
        platform: undefined,
        compact: false,
        interactive: false,
        lazy: true,
    },
);

const mediaFailed = ref(false);
const frameReady = ref(false);

const interactiveSrc = computed(() => {
    if (!props.interactive || !props.embed?.src) {
        return null;
    }

    if (props.embed.provider === 'instagram') {
        return props.embed.src.replace('/embed/captioned/', '/embed/');
    }

    return props.embed.src;
});

const usableCoverUrl = computed(() => {
    if (!props.coverUrl || !isDurableMediaSrc(props.coverUrl)) {
        return null;
    }

    return props.coverUrl;
});

const usableStillMediaUrl = computed(() => {
    // Compact proof sheets are cover-only - never lean on media_url (often CDN).
    if (props.compact || mediaFailed.value || !props.mediaUrl) {
        return null;
    }

    if (!isDisplayableImageSrc(props.mediaUrl) || !isDurableMediaSrc(props.mediaUrl)) {
        return null;
    }

    return props.mediaUrl;
});

const usableVideoMediaUrl = computed(() => {
    // Never use a CDN reel as the preview. Compact cells stay stills only.
    if (
        props.compact
        || mediaFailed.value
        || !props.mediaUrl
        || usableCoverUrl.value
        || usableStillMediaUrl.value
    ) {
        return null;
    }

    const url = props.mediaUrl.split('?')[0]?.toLowerCase() ?? '';

    if (!/\.(mp4|webm|ogg|m4v)$/i.test(url)) {
        return null;
    }

    if (!isDurableMediaSrc(props.mediaUrl)) {
        return null;
    }

    return props.mediaUrl;
});

function onMediaError(): void {
    mediaFailed.value = true;
}

watch(
    () => props.mediaUrl,
    () => {
        mediaFailed.value = false;
    },
);

watch(interactiveSrc, () => {
    frameReady.value = false;
});
</script>

<template>
    <div
        class="snitch-platform-embed"
        :class="compact ? 'snitch-platform-embed-compact' : 'snitch-platform-embed-detail'"
        :data-embed-provider="embed?.provider"
    >
        <iframe
            v-if="interactiveSrc"
            :src="interactiveSrc"
            :title="embed?.title ?? 'Post'"
            class="snitch-platform-embed-frame"
            :class="{ 'snitch-platform-embed-frame-ready': frameReady }"
            loading="lazy"
            allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"
            referrerpolicy="strict-origin-when-cross-origin"
            @load="frameReady = true"
        />
        <div class="snitch-platform-embed-fallback">
            <SnitchImage
                v-if="usableCoverUrl"
                :src="usableCoverUrl"
                alt=""
                class="snitch-platform-embed-fallback-img size-full"
                img-class="size-full object-cover"
                fallback="paper"
            />
            <SnitchImage
                v-else-if="usableStillMediaUrl"
                :src="usableStillMediaUrl"
                alt=""
                class="snitch-platform-embed-fallback-img size-full"
                img-class="size-full object-cover"
                fallback="paper"
            />
            <video
                v-else-if="usableVideoMediaUrl"
                :src="usableVideoMediaUrl"
                class="snitch-platform-embed-fallback-img"
                muted
                playsinline
                preload="metadata"
                @error="onMediaError"
            />
            <SnitchImage
                v-else
                :src="null"
                alt=""
                class="snitch-platform-embed-fallback-img size-full"
                img-class="size-full object-cover"
                fallback="paper"
            />
        </div>

        <a
            v-if="postUrl && !interactiveSrc"
            :href="postUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="snitch-platform-embed-open"
            @click.stop
        >
            {{ openOnPlatformLabel(platform) }}
        </a>
    </div>
</template>
