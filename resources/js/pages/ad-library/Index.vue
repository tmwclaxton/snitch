<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, Megaphone, Users } from '@lucide/vue';
import { computed } from 'vue';
import { show as competitorShow, index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import SnitchSkeleton from '@/components/SnitchSkeleton.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { platformLabel } from '@/lib/platforms';

type AdRow = {
    id: number;
    title: string;
    body: string | null;
    url: string;
    platform: string;
    last_seen_at: string | null;
    tracked_account: { id: number | null; handle: string } | null;
};

const props = defineProps<{
    ads?: AdRow[] | null;
    total: number;
}>();

defineOptions({
    layout: AppLayout,
});

const adsLoaded = computed(() => Array.isArray(props.ads));
const adsList = computed(() => props.ads ?? []);

function seenLabel(iso: string | null): string | null {
    if (!iso) {
        return null;
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-2 py-6 sm:px-3 sm:py-8">
        <Head title="Active ads" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="snitch-app-canvas">
            <header class="border-b border-snitch-ink/10 pb-5">
                <p class="snitch-ink-label">Snitch / Ads</p>
                <h1 class="snitch-display mt-1.5 text-3xl text-snitch-ink sm:text-4xl">
                    Active ads
                </h1>
                <p class="mt-1.5 max-w-2xl text-sm text-snitch-ink/65 sm:text-base">
                    Meta Ad Library hits for accounts you track.
                    <span
                        v-if="total > 0"
                        class="tabular-nums text-snitch-ink/80"
                    >
                        {{ total }} running.
                    </span>
                </p>
            </header>

            <div
                v-if="!adsLoaded"
                class="mt-6 grid gap-3 sm:grid-cols-2"
                aria-live="polite"
                aria-busy="true"
            >
                <SnitchSkeleton
                    v-for="row in 4"
                    :key="`ad-skel-${row}`"
                    variant="scrap"
                    height="7rem"
                    :label="`Loading ad ${row}`"
                />
            </div>

            <div
                v-else-if="adsList.length"
                class="mt-6 grid gap-3 sm:grid-cols-2"
            >
                <article
                    v-for="ad in adsList"
                    :key="ad.id"
                    class="snitch-scrap relative p-4 pt-5"
                >
                    <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <p class="snitch-ink-label">
                            {{ platformLabel(ad.platform) }}
                        </p>
                        <Link
                            v-if="ad.tracked_account?.id"
                            :href="competitorShow.url(ad.tracked_account.id)"
                            class="text-xs font-medium text-snitch-ink/60 underline decoration-snitch-ink/20 underline-offset-4"
                        >
                            @{{ ad.tracked_account.handle }}
                        </Link>
                        <span
                            v-else-if="ad.tracked_account?.handle"
                            class="text-xs text-snitch-ink/55"
                        >
                            @{{ ad.tracked_account.handle }}
                        </span>
                        <span
                            v-if="seenLabel(ad.last_seen_at)"
                            class="ms-auto text-[11px] tabular-nums text-snitch-ink/45"
                        >
                            Seen {{ seenLabel(ad.last_seen_at) }}
                        </span>
                    </div>
                    <h2 class="snitch-display mt-2 text-xl leading-snug text-snitch-ink">
                        {{ ad.title }}
                    </h2>
                    <p
                        v-if="ad.body"
                        class="mt-2 line-clamp-3 text-sm leading-relaxed text-snitch-ink/70"
                    >
                        {{ ad.body }}
                    </p>
                    <a
                        :href="ad.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="snitch-btn snitch-btn-ghost mt-4 px-3 py-1.5 text-sm"
                    >
                        <ExternalLink class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                        <span class="relative z-10">Open in Ad Library</span>
                    </a>
                </article>
            </div>

            <div
                v-else
                class="snitch-scrap relative mx-auto mt-8 max-w-md p-8 text-center sm:p-10"
            >
                <span class="snitch-tape left-8 -top-2" aria-hidden="true" />
                <Megaphone class="mx-auto size-8 text-snitch-ink/35" aria-hidden="true" />
                <p class="snitch-display mt-3 text-xl">No library ads yet</p>
                <p class="mt-2 text-sm text-snitch-ink/65">
                    Sync Instagram or Facebook accounts to pull Meta Ad Library hits.
                </p>
                <Link
                    :href="competitors.url()"
                    class="snitch-btn snitch-btn-spot mt-5"
                >
                    <span class="relative z-10 inline-flex items-center gap-2">
                        <Users class="size-3.5 shrink-0" aria-hidden="true" />
                        Tracking
                    </span>
                </Link>
            </div>
        </div>
    </div>
</template>
