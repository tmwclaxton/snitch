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
        body: 'Set your brand context, then add Instagram snitch handles - or use suggest to find public accounts in your niche (rivals or style inspiration). Snitch syncs recent public Instagram posts into one feed.',
    },
    {
        title: 'Analyse',
        icon: Sparkles,
        body: 'Video posts get full-video analysis: opening hook, visual craft, SFX and music cues, concept, and how-to-copy notes - tagged with catalogue terms you can filter in Explore.',
    },
    {
        title: 'Winners',
        icon: Trophy,
        body: 'Your quality rules score the board. Open a shortlist of posts that clear the bar, with why they won and how to remake the move in your brand voice.',
    },
];
</script>

<template>
    <div class="px-4 py-14 sm:px-8 sm:py-20">
        <div class="mx-auto max-w-6xl">
            <h1 class="snitch-hero-display text-4xl text-snitch-caution-fog sm:text-5xl">
                Track. Analyse. Remake the winners.
            </h1>
            <p class="mt-5 max-w-2xl text-base leading-relaxed text-snitch-caution-fog/80 md:text-lg">
                Three steps from empty account to a shortlist of Instagram posts worth
                stealing (ethically). Public posts only - no private
                credentials for rival accounts.
            </p>

            <ol class="mt-12 grid gap-6 md:grid-cols-3">
                <li
                    v-for="(step, index) in steps"
                    :key="step.title"
                    class="list-none border border-white/10 bg-[#141416] p-6"
                >
                    <p class="flex items-center gap-2 font-mono text-3xl font-bold text-snitch-caution-yellow">
                        <component
                            :is="step.icon"
                            class="size-6 shrink-0 text-snitch-caution-fog/70"
                            aria-hidden="true"
                        />
                        0{{ index + 1 }}
                    </p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-snitch-caution-fog">
                        {{ step.title }}
                    </h2>
                    <p class="mt-3 text-sm leading-relaxed text-snitch-caution-fog/80">
                        {{ step.body }}
                    </p>
                </li>
            </ol>

            <div class="mt-12">
                <Link
                    :href="login()"
                    class="inline-flex items-center gap-2 bg-snitch-caution-yellow px-5 py-2.5 text-sm font-semibold uppercase tracking-wide text-snitch-caution-ink hover:opacity-90"
                >
                    <ArrowRight class="size-4 shrink-0" aria-hidden="true" />
                    Get started
                </Link>
            </div>
        </div>
    </div>
</template>
