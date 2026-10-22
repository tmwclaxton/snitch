<script setup lang="ts">
import { formatAppDate } from '@/lib/dates';

defineProps<{
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
    };
}>();

function postedLabel(iso: string | null | undefined): string {
    return formatAppDate(iso, { month: 'short', day: 'numeric' }) ?? '';
}
</script>

<template>
    <article class="flex h-full flex-col rounded border border-snitch-ink/10 bg-snitch-lift p-4">
        <p class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
            {{ title }}
        </p>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
            <span class="font-medium text-snitch-ink">@{{ payload.handle }}</span>
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
        <p
            v-if="payload.caption"
            class="mt-2 text-sm leading-relaxed text-snitch-ink/80"
        >
            {{ payload.caption }}
        </p>
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
