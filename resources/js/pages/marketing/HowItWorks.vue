<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import SeoHead from '@/components/marketing/SeoHead.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { login } from '@/routes';

defineOptions({
    layout: PublicLayout,
});

const steps = [
    {
        title: 'Track',
        body: 'Connect your brand context, then add competitor handles on TikTok, Instagram, YouTube Shorts, Facebook, and LinkedIn. Snitch syncs recent public reels into one feed.',
    },
    {
        title: 'Analyze',
        body: 'Video and reel posts get full-video analysis: the opening hook, visual summary, SFX, music cues, and the core idea - annotated on the frame like stickers on a scrap.',
    },
    {
        title: 'Winners',
        body: 'Your quality rules score the board. Open a tear sheet of posts that clear the bar, with why they won and how to copy the move.',
    },
];
</script>

<template>
    <div>
        <SeoHead
            title="How it works"
            description="Track competitors, analyze posts, and surface winners with Snitch."
            path="/how-it-works"
        />

        <div class="px-5 py-14 sm:px-8 sm:py-20">
            <div class="mx-auto max-w-6xl">
                <h1
                    class="snitch-display text-4xl text-snitch-ink sm:text-5xl"
                >
                    Track. Analyze. Remake the winners.
                </h1>
                <p class="mt-4 max-w-2xl text-snitch-ink/80">
                    Three steps from empty account to a scrapbook of posts worth
                    stealing (ethically).
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
                            class="snitch-annotation text-3xl font-medium text-snitch-ink"
                        >
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
                    <Link :href="login()" class="snitch-btn">Get started</Link>
                </div>
            </div>
        </div>
    </div>
</template>
