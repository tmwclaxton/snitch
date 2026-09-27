<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { openOnPlatformLabel, productPlatformLabel } from '@/lib/platforms';

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

const coverFailed = ref(false);
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
    if (coverFailed.value || !props.coverUrl) {
        return null;
    }

    return props.coverUrl;
});

const usableMediaUrl = computed(() => {
    if (mediaFailed.value || !props.mediaUrl) {
        return null;
    }

    return props.mediaUrl;
});

const isVideoMedia = computed(() => {
    const url = (usableMediaUrl.value ?? '').split('?')[0]?.toLowerCase() ?? '';

    return /\.(mp4|webm|ogg|m4v)$/i.test(url);
});

function onCoverError(): void {
    coverFailed.value = true;
}

function onMediaError(): void {
    mediaFailed.value = true;
}

watch(
    () => props.coverUrl,
    () => {
        coverFailed.value = false;
    },
);

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
            <img
                v-if="usableCoverUrl"
                :src="usableCoverUrl"
                alt=""
                class="snitch-platform-embed-fallback-img"
                loading="lazy"
                decoding="async"
                @error="onCoverError"
            />
            <img
                v-else-if="usableMediaUrl && !isVideoMedia"
                :src="usableMediaUrl"
                alt=""
                class="snitch-platform-embed-fallback-img"
                loading="lazy"
                decoding="async"
                @error="onMediaError"
            />
            <video
                v-else-if="usableMediaUrl && isVideoMedia"
                :src="usableMediaUrl"
                class="snitch-platform-embed-fallback-img"
                muted
                playsinline
                preload="metadata"
                @error="onMediaError"
            />
            <div
                v-else
                class="snitch-platform-embed-fallback-empty"
            >
                <p class="text-xs font-medium text-neutral-500">
                    Preview unavailable
                </p>
                <p class="mt-1 text-[11px] text-neutral-400">
                    {{ productPlatformLabel(platform) }} media link expired
                </p>
            </div>
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
