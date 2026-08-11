<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Sparkles, Trophy, Users } from '@lucide/vue';
import type { Component } from 'vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { login } from '@/routes';

defineOptions({
    layout: PublicLayout,
});

const steps: Array<{ title: string; body: string; icon: Component }> = [
    {
        title: 'Track',
        icon: Users,
        body: 'Set your brand context, then add snitch handles on TikTok, Instagram, YouTube Shorts, Facebook, and LinkedIn - or use suggest to find public accounts in your niche (rivals or style inspiration). Snitch syncs recent public reels and Shorts into one feed (images and carousels stay out).',
    },
    {
        title: 'Analyse',
        icon: Sparkles,
        body: 'Video posts get full-video analysis: opening hook, visual craft, SFX and music cues, concept, and how-to-copy notes - tagged with catalogue terms you can filter in Explore. Stickers sit on the frame like scrap annotations.',
    },
    {
        title: 'Winners',
        icon: Trophy,
        body: 'Your quality rules score the board. Open a tear sheet of posts that clear the bar, with why they won and how to remake the move in your brand voice.',
    },
];
</script>

<template>
    <div>
        <div class="px-5 py-14 sm:px-8 sm:py-20">
            <div class="mx-auto max-w-6xl">
                <h1
                    class="snitch-display text-4xl text-snitch-ink sm:text-5xl"
                >
                    Track. Analyse. Remake the winners.
                </h1>
                <p class="mt-4 max-w-2xl text-snitch-ink/80">
                    Three steps from empty account to a scrapbook of posts worth
                    stealing (ethically). Public posts only - no private
                    credentials for rival accounts.
                </p>

                <ol class="snitch-contact-reveal mt-12 grid gap-6 md:grid-cols-3">
                    <li
                        v-for="(step, index) in steps"
                        :key="step.title"
                        class="snitch-scrap relative list-none p-6 pt-8"
                    >
                        <span
                            class="snitch-tape left-5 -top-2"
                            aria-hidden="true"
                        />
                        <p
                            class="flex items-center gap-2 snitch-annotation text-3xl font-medium text-snitch-ink"
                        >
                            <component
                                :is="step.icon"
                                class="size-6 shrink-0 text-snitch-ink/55"
                                aria-hidden="true"
                            />
                            0{{ index + 1 }}
                        </p>
                        <h2
                            class="snitch-display mt-2 text-2xl text-snitch-ink"
                        >
                            <span class="snitch-marker-underline">{{
                                step.title
                            }}</span>
                        </h2>
                        <p
                            class="relative z-10 mt-3 text-sm leading-relaxed text-snitch-ink/80"
                        >
                            {{ step.body }}
                        </p>
                    </li>
                </ol>

                <div class="mt-12">
                    <Link :href="login()" class="snitch-btn">
                        <span class="relative z-10 inline-flex items-center gap-2">
                            <ArrowRight class="size-3.5 shrink-0" aria-hidden="true" />
                            Get started
                        </span>
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
