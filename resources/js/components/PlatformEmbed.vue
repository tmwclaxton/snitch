<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { acquireEmbedSlot, releaseEmbedSlot } from '@/lib/embedLoadQueue';
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
const isVisible = ref(!props.lazy);
const shouldLoad = ref(false);
const iframeReady = ref(false);
const embedFailed = ref(false);
const mediaFailed = ref(false);
let observer: IntersectionObserver | null = null;
let slotHeld = false;
let cancelled = false;
let loadGeneration = 0;
let slotTimeoutId: ReturnType<typeof setTimeout> | null = null;

const canEmbed = computed(
    () => Boolean(props.embed?.src) && shouldLoad.value && !embedFailed.value,
);

const showFallback = computed(() => !canEmbed.value || !iframeReady.value);

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

function onMediaError(): void {
    mediaFailed.value = true;
}

function clearSlotTimeout(): void {
    if (slotTimeoutId === null) {
        return;
    }

    clearTimeout(slotTimeoutId);
    slotTimeoutId = null;
}

async function requestLoad(generation: number): Promise<void> {
    if (!props.embed?.src || shouldLoad.value || cancelled) {
        return;
    }

    await acquireEmbedSlot();

    if (cancelled || generation !== loadGeneration || !isVisible.value) {
        releaseEmbedSlot();

        return;
    }

    slotHeld = true;
    shouldLoad.value = true;

    // Platforms sometimes never fire load/error; free the queue anyway.
    clearSlotTimeout();
    slotTimeoutId = setTimeout(() => {
        if (generation === loadGeneration) {
            releaseSlotIfHeld();
        }
    }, 10000);
}

function releaseSlotIfHeld(): void {
    if (!slotHeld) {
        return;
    }

    slotHeld = false;
    clearSlotTimeout();
    releaseEmbedSlot();
}

function onIframeSettled(): void {
    iframeReady.value = true;
    releaseSlotIfHeld();
}

function onEmbedError(): void {
    embedFailed.value = true;
    iframeReady.value = false;
    releaseSlotIfHeld();
}

function startObserving(): void {
    if (!props.lazy) {
        isVisible.value = true;
        void requestLoad(loadGeneration);

        return;
    }

    if (isVisible.value || !root.value) {
        return;
    }

    if (typeof IntersectionObserver === 'undefined') {
        isVisible.value = true;
        void requestLoad(loadGeneration);

        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                isVisible.value = true;
                observer?.disconnect();
                observer = null;
                void requestLoad(loadGeneration);
            }
        },
        { rootMargin: '120px 0px' },
    );

    observer.observe(root.value);
}

function resetForEmbedChange(): void {
    cancelled = true;
    loadGeneration += 1;
    cancelled = false;
    observer?.disconnect();
    observer = null;
    releaseSlotIfHeld();
    clearSlotTimeout();
    isVisible.value = !props.lazy;
    shouldLoad.value = false;
    iframeReady.value = false;
    embedFailed.value = false;
    mediaFailed.value = false;
}

watch(
    () => props.mediaUrl,
    () => {
        mediaFailed.value = false;
    },
);

onMounted(() => {
    startObserving();
});

onBeforeUnmount(() => {
    cancelled = true;
    observer?.disconnect();
    observer = null;
    releaseSlotIfHeld();
    clearSlotTimeout();
});

watch(
    () => props.embed?.src,
    () => {
        resetForEmbedChange();
        startObserving();
    },
);

watch(
    () => props.lazy,
    () => {
        resetForEmbedChange();
        startObserving();
    },
);
</script>

<template>
    <div
        ref="root"
        class="snitch-platform-embed"
        :class="compact ? 'snitch-platform-embed-compact' : 'snitch-platform-embed-detail'"
        :style="embed && !embedFailed ? { aspectRatio: embed.aspect } : undefined"
    >
        <div
            v-if="showFallback"
            class="snitch-platform-embed-fallback"
            aria-hidden="true"
        >
            <video
                v-if="usableMediaUrl && isVideoMedia"
                :src="usableMediaUrl"
                class="snitch-platform-embed-fallback-img"
                muted
                playsinline
                preload="metadata"
                @error="onMediaError"
            />
            <img
                v-else-if="usableMediaUrl"
                :src="usableMediaUrl"
                alt=""
                class="snitch-platform-embed-fallback-img"
                loading="lazy"
                decoding="async"
                @error="onMediaError"
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
        </div>

        <iframe
            v-if="canEmbed"
            :src="embed!.src"
            :title="embed!.title"
            class="snitch-platform-embed-frame"
            :class="{ 'snitch-platform-embed-frame-ready': iframeReady }"
            loading="lazy"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            allowfullscreen
            referrerpolicy="strict-origin-when-cross-origin"
            @load="onIframeSettled"
            @error="onEmbedError"
        />

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
