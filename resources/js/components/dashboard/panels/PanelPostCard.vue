<script setup lang="ts">
import { computed, ref } from 'vue';
import { captionExcerpt } from '@/lib/captionExcerpt';
import { formatAppDate } from '@/lib/dates';

const props = defineProps<{
    title: string;
    payload: {
        post_id?: number | null;
        handle?: string;
        format?: string | null;
        url?: string | null;
        posted_at?: string | null;
        caption?: string | null;
        likes?: number | null;
        comments?: number | null;
        views?: number | null;
        times_usual_label?: string | null;
        thumbnail_url?: string | null;
    };
}>();

const expanded = ref(false);

const captionParts = computed(() => captionExcerpt(props.payload.caption, 220));

const shownCaption = computed(() => {
    if (expanded.value || ! captionParts.value.truncated) {
        return (props.payload.caption ?? '').trim();
    }

    return captionParts.value.excerpt;
});

function postedLabel(iso: string | null | undefined): string {
    return formatAppDate(iso, { month: 'short', day: 'numeric' }) ?? '';
}
</script>

<template>
    <article class="flex flex-col self-start rounded border border-snitch-ink/10 bg-snitch-lift p-4">
        <p class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
            {{ title }}
        </p>
        <div
            v-if="payload.thumbnail_url"
            class="mt-3 overflow-hidden rounded border border-snitch-ink/10 bg-[#1a1a1d]"
        >
            <img
                :src="payload.thumbnail_url"
                alt=""
                class="aspect-square w-full max-h-48 object-cover"
                loading="lazy"
            >
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
            <a
                v-if="payload.handle"
                :href="`https://www.instagram.com/${payload.handle}/`"
                target="_blank"
                rel="noopener noreferrer"
                class="font-medium text-snitch-ink underline-offset-2 hover:underline"
            >@{{ payload.handle }}</a>
            <span
                v-if="payload.format"
                class="snitch-format-tag"
            >{{ payload.format }}</span>
            <span
                v-if="payload.posted_at"
                class="font-mono tabular-nums text-snitch-ink/60"
            >{{ postedLabel(payload.posted_at) }}</span>
        </div>
        <p
            v-if="payload.times_usual_label"
            class="mt-2 text-sm font-medium text-snitch-ink"
        >
            {{ payload.times_usual_label }}
        </p>
        <div
            v-if="shownCaption"
            class="mt-2"
        >
            <p class="whitespace-pre-wrap text-sm leading-relaxed text-snitch-ink/80">
                {{ shownCaption }}
            </p>
            <button
                v-if="captionParts.truncated"
                type="button"
                class="mt-1 text-sm font-medium text-snitch-ink underline-offset-2 hover:underline"
                @click="expanded = ! expanded"
            >
                {{ expanded ? 'Show less' : 'Show more' }}
            </button>
        </div>
        <dl class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-snitch-ink/70">
            <div
                v-if="payload.likes != null"
                class="flex gap-1"
            >
                <dt>Likes</dt>
                <dd class="font-mono tabular-nums text-snitch-ink">{{ payload.likes }}</dd>
            </div>
            <div
                v-if="payload.comments != null"
                class="flex gap-1"
            >
                <dt>Comments</dt>
                <dd class="font-mono tabular-nums text-snitch-ink">{{ payload.comments }}</dd>
            </div>
            <div
                v-if="payload.views != null"
                class="flex gap-1"
            >
                <dt>Views</dt>
                <dd class="font-mono tabular-nums text-snitch-ink">{{ payload.views }}</dd>
            </div>
        </dl>
        <a
            v-if="payload.url"
            :href="payload.url"
            target="_blank"
            rel="noopener noreferrer"
            class="snitch-btn mt-4 inline-flex w-fit px-3 py-1.5 text-sm font-medium"
        >
            Open post
        </a>
    </article>
</template>
