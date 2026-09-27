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
            <h1 class="text-4xl font-semibold tracking-tight text-neutral-950 sm:text-5xl">
                Track. Analyse. Remake the winners.
            </h1>
            <p class="mt-4 max-w-2xl text-neutral-600">
                Three steps from empty account to a shortlist of Instagram posts worth
                stealing (ethically). Public posts only - no private
                credentials for rival accounts.
            </p>

            <ol class="mt-12 grid gap-6 md:grid-cols-3">
                <li
                    v-for="(step, index) in steps"
                    :key="step.title"
                    class="list-none border border-neutral-200 bg-white p-6 shadow-[0_20px_50px_-15px_rgba(0,0,0,0.10)]"
                >
                    <p class="flex items-center gap-2 text-3xl font-semibold text-neutral-950">
                        <component
                            :is="step.icon"
                            class="size-6 shrink-0 text-neutral-500"
                            aria-hidden="true"
                        />
                        0{{ index + 1 }}
                    </p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-neutral-950">
                        {{ step.title }}
                    </h2>
                    <p class="mt-3 text-sm leading-relaxed text-neutral-600">
                        {{ step.body }}
                    </p>
                </li>
            </ol>

            <div class="mt-12">
                <Link
                    :href="login()"
                    class="inline-flex items-center gap-2 bg-[#F0C400] px-5 py-2.5 text-sm font-medium text-neutral-950 hover:opacity-90"
                >
                    <ArrowRight class="size-3.5 shrink-0" aria-hidden="true" />
                    Get started
                </Link>
            </div>
        </div>
    </div>
</template>
