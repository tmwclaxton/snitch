<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { index as feedIndex } from '@/actions/App/Http/Controllers/FeedController';
import type { EmbedConfig } from '@/components/PlatformEmbed.vue';
import PlatformEmbed from '@/components/PlatformEmbed.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { platformLabel } from '@/lib/platforms';

type Analysis = {
    status: string;
    hook: string | null;
    hook_window_end_sec: number | null;
    visual_summary: string | null;
    idea: string | null;
    cta: string | null;
    sfx: Array<{ at_sec?: number | null; label: string; role?: string | null }> | null;
    music: Record<string, unknown> | null;
};

defineProps<{
    post: {
        id: number;
        platform: string;
        type: string;
        url: string;
        caption: string | null;
        media_url: string | null;
        metrics: Record<string, number> | null;
        embed?: EmbedConfig | null;
        tracked_account?: { handle: string; display_name: string | null };
        analysis?: Analysis | null;
        winner_insight?: { score: number; why: string; how_to_copy: string } | null;
    };
}>();

defineOptions({
    layout: AppLayout,
});
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Post detail" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto grid max-w-5xl gap-8 lg:grid-cols-[1.05fr_0.95fr]">
            <div>
                <Link
                    :href="feedIndex.url()"
                    class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                >
                    Back to feed
                </Link>
                <div class="snitch-polaroid relative mt-4" style="--snitch-tilt: -0.6deg">
                    <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
                    <div class="snitch-polaroid-frame !aspect-auto overflow-hidden">
                        <PlatformEmbed
                            :embed="post.embed"
                            :media-url="post.media_url"
                            :post-url="post.url"
                            :platform="post.platform"
                            :lazy="false"
                        />
                    </div>
                </div>
                <a
                    v-if="post.url"
                    :href="post.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="snitch-btn snitch-btn-ghost mt-4 inline-flex px-3 py-1.5 text-sm"
                >
                    View on {{ platformLabel(post.platform) }}
                </a>
            </div>

            <div class="space-y-4">
                <p class="snitch-ink-label">
                    {{ platformLabel(post.platform) }} / {{ post.type }}
                </p>
                <h1 class="snitch-display mt-2 text-3xl text-snitch-ink">
                    @{{ post.tracked_account?.handle }}
                </h1>
                <p
                    v-if="post.caption"
                    class="text-sm leading-relaxed text-snitch-ink/70"
                >
                    {{ post.caption }}
                </p>

                <template v-if="post.analysis?.status === 'completed'">
                    <div class="snitch-sticker" style="--snitch-tilt: -1.4deg">
                        <p class="snitch-annotation text-xl">Hook</p>
                        <p class="mt-1 text-snitch-ink">{{ post.analysis.hook }}</p>
                        <p class="mt-2 text-xs text-snitch-ink/50">
                            Window ~{{ post.analysis.hook_window_end_sec }}s
                        </p>
                    </div>
                    <div class="snitch-sticker" style="--snitch-tilt: 1.1deg">
                        <p class="snitch-annotation text-xl">Visual</p>
                        <p class="mt-1 text-sm text-snitch-ink/85">
                            {{ post.analysis.visual_summary }}
                        </p>
                    </div>
                    <div class="snitch-sticker" style="--snitch-tilt: -0.5deg">
                        <p class="snitch-annotation text-xl">Idea</p>
                        <p class="mt-1 text-snitch-ink">{{ post.analysis.idea }}</p>
                    </div>
                    <div class="snitch-sticker" style="--snitch-tilt: 0.8deg">
                        <p class="snitch-annotation text-xl">SFX</p>
                        <ul v-if="post.analysis.sfx?.length" class="mt-2 space-y-1 text-sm">
                            <li v-for="(fx, i) in post.analysis.sfx" :key="i">
                                <span class="snitch-marker-underline">{{ fx.label }}</span>
                                <span v-if="fx.at_sec != null" class="text-snitch-ink/45">
                                    @ {{ fx.at_sec }}s
                                </span>
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-sm text-snitch-ink/55">No SFX tagged.</p>
                    </div>
                    <div class="snitch-sticker" style="--snitch-tilt: -1deg">
                        <p class="snitch-annotation text-xl">CTA</p>
                        <p class="mt-1">{{ post.analysis.cta }}</p>
                    </div>
                </template>

                <div
                    v-else
                    class="snitch-sticker animate-pulse space-y-3"
                    style="--snitch-tilt: 0deg"
                >
                    <div class="h-4 w-24 bg-snitch-ink/10" />
                    <div class="h-16 bg-snitch-ink/10" />
                    <div class="h-16 bg-snitch-ink/10" />
                    <p class="text-sm text-snitch-ink/50">Analysis pending…</p>
                </div>
            </div>
        </div>
    </div>
</template>
