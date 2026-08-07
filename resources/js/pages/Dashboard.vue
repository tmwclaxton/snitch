<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import { index as feed } from '@/actions/App/Http/Controllers/FeedController';
import { index as winners } from '@/actions/App/Http/Controllers/WinnerController';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const panels = [
    {
        title: 'Feed',
        copy: 'Open the contact sheet of every tracked post.',
        href: feed.url(),
        stamp: 'Frames',
    },
    {
        title: 'Competitors',
        copy: 'Cutout profiles you are watching across platforms.',
        href: competitors.url(),
        stamp: 'Accounts',
    },
    {
        title: 'Winners',
        copy: 'Tear sheet of posts that cleared your rules.',
        href: winners.url(),
        stamp: 'Score',
    },
];
</script>

<template>
    <div class="snitch-app-shell relative min-h-full p-6">
        <Head title="Dashboard" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-5xl">
            <h1 class="snitch-display relative text-4xl text-snitch-ink">
                <span
                    class="pointer-events-none absolute inset-0 translate-x-[2px] translate-y-[1px] text-snitch-spot opacity-55 mix-blend-multiply select-none"
                    aria-hidden="true"
                >Snitch</span>
                <span class="relative">Snitch</span>
            </h1>
            <p class="mt-2 max-w-lg text-snitch-ink/70">
                Your board for competitor posts, analysis stickers, and winners
                worth remaking.
            </p>

            <div class="snitch-contact-reveal mt-10 grid gap-5 md:grid-cols-3">
                <Link
                    v-for="(panel, index) in panels"
                    :key="panel.title"
                    :href="panel.href"
                    class="snitch-scrap relative block p-6 pt-8 transition hover:-translate-y-0.5"
                    :style="{
                        '--snitch-tilt': index === 1 ? '1deg' : '-1deg',
                    }"
                >
                    <span
                        class="snitch-tape"
                        :class="index === 1 ? 'left-5 -top-2' : 'right-4 -top-2'"
                        aria-hidden="true"
                    />
                    <span class="snitch-stamp">{{ panel.stamp }}</span>
                    <h2
                        class="snitch-display mt-4 text-2xl text-snitch-ink"
                    >
                        {{ panel.title }}
                    </h2>
                    <p class="mt-2 text-sm leading-relaxed text-snitch-ink/70">
                        {{ panel.copy }}
                    </p>
                </Link>
            </div>
        </div>
    </div>
</template>
