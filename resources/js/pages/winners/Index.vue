<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import { rescore } from '@/actions/App/Http/Controllers/WinnerController';
import type {
    WinnerRuleFormData,
    WinnerRulePreset,
} from '@/components/WinnerRulesForm.vue';
import WinnerRulesModal from '@/components/WinnerRulesModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{
    winners: Array<{
        id: number;
        score: number;
        why: string;
        how_to_copy: string;
        post: {
            id: number;
            media_url: string | null;
            platform: string;
            tracked_account?: { handle: string };
            analysis?: { hook: string | null } | null;
        };
    }>;
    rule: WinnerRuleFormData;
    presets: Record<string, WinnerRulePreset>;
}>();

defineOptions({
    layout: AppLayout,
});

const rulesOpen = ref(false);
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Winners" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                        Tear sheet
                    </h1>
                    <p class="mt-1.5 text-sm text-snitch-ink/65 sm:text-base">
                        Preset {{ rule.preset }} · min {{ rule.min_views }} views /
                        {{ rule.min_likes }} likes
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-ghost"
                        @click="rulesOpen = true"
                    >
                        Rules
                    </button>
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-spot"
                        @click="router.post(rescore.url())"
                    >
                        <span class="relative z-10">Rescore</span>
                    </button>
                </div>
            </div>

            <div class="snitch-tear-board mt-8 p-4 sm:p-6">
                <div class="snitch-contact-reveal columns-1 gap-4 md:columns-2 lg:columns-3">
                    <article
                        v-for="(winner, index) in winners"
                        :key="winner.id"
                        class="snitch-polaroid relative mb-4 break-inside-avoid"
                        :style="{
                            '--snitch-tilt': index % 2 === 0 ? '-1.4deg' : '1.2deg',
                        }"
                    >
                        <span
                            class="snitch-tape -top-2"
                            :class="index % 2 === 0 ? 'left-4' : 'right-4'"
                            aria-hidden="true"
                        />
                        <Link :href="feedShow.url(winner.post.id)" class="block">
                            <div class="snitch-polaroid-frame">
                                <img
                                    v-if="winner.post.media_url"
                                    :src="winner.post.media_url"
                                    alt=""
                                />
                            </div>
                            <div class="mt-3 space-y-2 px-0.5">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="snitch-ink-label">#{{ index + 1 }}</span>
                                    <span class="snitch-annotation text-xl">
                                        {{ winner.score.toFixed(1) }}
                                    </span>
                                </div>
                                <p class="text-xs uppercase tracking-wide text-snitch-ink/50">
                                    @{{ winner.post.tracked_account?.handle }} ·
                                    {{ winner.post.platform }}
                                </p>
                                <p class="text-sm text-snitch-ink/85">{{ winner.why }}</p>
                                <div class="border-t border-dashed border-snitch-ink/15 pt-3">
                                    <p class="snitch-annotation text-lg">How to copy</p>
                                    <p class="mt-1 whitespace-pre-line text-sm text-snitch-ink/75">
                                        {{ winner.how_to_copy }}
                                    </p>
                                </div>
                            </div>
                        </Link>
                    </article>
                </div>

                <div
                    v-if="!winners.length"
                    class="snitch-scrap relative mx-auto max-w-md p-8 text-center"
                >
                    <span class="snitch-tape left-8 -top-2" aria-hidden="true" />
                    <p class="snitch-display text-2xl">No winners yet</p>
                    <p class="mt-2 text-sm text-snitch-ink/65">
                        Sync posts, wait for analysis, or loosen your rules.
                    </p>
                    <button
                        type="button"
                        class="snitch-btn snitch-btn-ghost mt-5"
                        @click="rulesOpen = true"
                    >
                        Edit rules
                    </button>
                </div>
            </div>
        </div>

        <WinnerRulesModal
            v-model:open="rulesOpen"
            :rule="rule"
            :presets="presets"
        />
    </div>
</template>
