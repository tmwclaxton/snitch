<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { platformLabel } from '@/lib/platforms';

export type EmbedConfig = {
    provider: string;
    src: string;
    title: string;
    aspect: string;
};

const props = withDefaults(
    defineProps<{
        embed?: EmbedConfig | null;
        mediaUrl?: string | null;
        postUrl?: string | null;
        platform?: string;
        compact?: boolean;
        lazy?: boolean;
    }>(),
    {
        embed: null,
        mediaUrl: null,
        postUrl: null,
        platform: undefined,
        compact: false,
        lazy: true,
    },
);

const root = ref<HTMLElement | null>(null);
const shouldLoad = ref(!props.lazy);
const embedFailed = ref(false);
let observer: IntersectionObserver | null = null;

const canEmbed = computed(
    () => Boolean(props.embed?.src) && shouldLoad.value && !embedFailed.value,
);

function startObserving(): void {
    if (!props.lazy || shouldLoad.value || !root.value) {
        return;
    }

    if (typeof IntersectionObserver === 'undefined') {
        shouldLoad.value = true;

        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                shouldLoad.value = true;
                observer?.disconnect();
                observer = null;
            }
        },
        { rootMargin: '200px 0px' },
    );

    observer.observe(root.value);
}

onMounted(() => {
    startObserving();
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});

watch(
    () => props.embed?.src,
    () => {
        embedFailed.value = false;
        shouldLoad.value = !props.lazy;
        observer?.disconnect();
        observer = null;
        startObserving();
    },
);

function onEmbedError(): void {
    embedFailed.value = true;
}
</script>

<template>
    <div
        ref="root"
        class="snitch-platform-embed"
        :class="compact ? 'snitch-platform-embed-compact' : 'snitch-platform-embed-detail'"
        :style="embed && !embedFailed ? { aspectRatio: embed.aspect } : undefined"
    >
        <iframe
            v-if="canEmbed"
            :src="embed!.src"
            :title="embed!.title"
            class="snitch-platform-embed-frame"
            loading="lazy"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowfullscreen
            referrerpolicy="strict-origin-when-cross-origin"
            @error="onEmbedError"
        />

        <template v-else>
            <img
                v-if="mediaUrl"
                :src="mediaUrl"
                alt=""
                class="snitch-platform-embed-fallback-img"
            />
            <div
                v-else
                class="snitch-platform-embed-fallback-empty"
            >
                <p class="snitch-ink-label">
                    {{ platform ? platformLabel(platform) : 'Post' }}
                </p>
                <p class="mt-1 text-xs text-snitch-paper/55">
                    {{ embedFailed ? 'Embed unavailable' : 'No preview' }}
                </p>
            </div>
        </template>

        <a
            v-if="postUrl && (embedFailed || !embed)"
            :href="postUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="snitch-platform-embed-open"
            @click.stop
        >
            Open on {{ platform ? platformLabel(platform) : 'platform' }}
        </a>
    </div>
</template>
