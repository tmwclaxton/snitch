<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({
    layout: PublicLayout,
});

const CTA_HREF = 'https://calendly.com/dan-olympuslab/30min';
const CTA_TEXT = 'Book a free intro call';

const scrolled = ref(false);

function onScroll(): void {
    scrolled.value = window.scrollY > 400;
}

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
});

onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
});

const benefits = [
    {
        title: "Know what's working before your competitors do",
        body: 'See which posts, formats and topics are driving engagement across your market. Every week, without opening Instagram once.',
    },
    {
        title: 'Stop guessing when to post',
        body: 'A heatmap of when competitors actually post, so you know exactly when is working best.',
    },
    {
        title: 'Spot growth (and decline) early',
        body: 'Follower trends, posting cadence and engagement. Tracked weekly so shifts in your market surface before they are obvious.',
    },
    {
        title: 'See their ad spend in action',
        body: 'Track which posts they are boosting, what the creative looks like, and how long each campaign runs before you spend a penny.',
    },
];

const faqs = [
    {
        q: 'Do I need to connect my Instagram account?',
        a: 'No. Snitch only reads public Instagram data on the competitors you add. Your own account is never connected, logged into or posted from.',
    },
    {
        q: 'How often does the data refresh?',
        a: 'Every week, automatically. Add a competitor and they will be pulled in the next weekly refresh.',
    },
    {
        q: 'How long does setup take?',
        a: 'About 60 seconds. Sign up, paste the Instagram handles you want to watch, and you are done. Your first report lands after the next weekly pull.',
    },
];
</script>

<template>
    <div class="bg-white text-neutral-950">
        <Head title="Better social media performance. Without manual research." />

        <section class="relative overflow-hidden border-b border-neutral-200">
            <div class="mx-auto flex min-h-[70vh] max-w-5xl flex-col justify-center px-4 py-24 text-center sm:py-32">
                <h1 class="text-[clamp(2.25rem,5vw,4rem)] font-semibold leading-[1.1] tracking-tight">
                    <span class="block">Better social media performance.</span>
                    <span class="block">Without manual research.</span>
                </h1>
                <p class="mx-auto mt-4 max-w-xl text-base text-neutral-700 md:text-lg">
                    Track your competitors and see the exact gap between you and them.
                    What's working, what you're missing, delivered instantly.
                </p>
                <div class="mt-8 flex justify-center">
                    <a
                        :href="CTA_HREF"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center bg-[#F0C400] px-5 py-2.5 text-sm font-medium text-neutral-950 hover:opacity-90"
                    >
                        {{ CTA_TEXT }}
                    </a>
                </div>
            </div>
        </section>

        <section class="border-b border-neutral-200 bg-neutral-50">
            <div class="mx-auto max-w-6xl px-4 py-8 text-center">
                <p class="text-sm text-neutral-500">
                    Tracking <span class="font-semibold text-neutral-950">27</span> brands ·
                    <span class="font-semibold text-neutral-950">781</span> posts analysed ·
                    <span class="font-semibold text-neutral-950">0</span> Instagram logins required
                </p>
            </div>
        </section>

        <section class="border-b border-neutral-200">
            <div class="mx-auto max-w-6xl px-4 py-24">
                <h2 class="max-w-2xl text-3xl font-semibold tracking-tight md:text-4xl">
                    Stop scrolling your competitors. Let Snitch do it.
                </h2>
                <div class="mt-12 grid gap-8 md:grid-cols-4">
                    <div
                        v-for="(benefit, index) in benefits"
                        :key="benefit.title"
                        class="border border-neutral-200 bg-white p-6 shadow-[0_20px_50px_-15px_rgba(0,0,0,0.10)]"
                    >
                        <div class="flex h-10 w-10 items-center justify-center bg-neutral-950 text-white">
                            <span class="text-sm font-semibold">0{{ index + 1 }}</span>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold tracking-tight">{{ benefit.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-neutral-500">{{ benefit.body }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-b border-neutral-200 bg-neutral-50">
            <div class="mx-auto max-w-4xl px-4 py-24 text-center">
                <h2 class="text-2xl font-semibold leading-snug tracking-tight md:text-3xl">
                    Less than the cost of a single sponsored post,
                    for insight that shapes every post you make.
                </h2>
                <div class="relative mt-10 inline-block w-full max-w-md text-left">
                    <div class="rounded-2xl bg-white p-8 shadow-[0_20px_50px_-15px_rgba(0,0,0,0.10)]">
                        <div class="flex items-baseline justify-between">
                            <h3 class="text-2xl font-semibold tracking-tight">Full Access</h3>
                            <p class="text-3xl font-semibold tracking-tight">
                                £50<span class="text-base font-medium text-neutral-500">/mo</span>
                            </p>
                        </div>
                        <ul class="mt-6 space-y-3 text-sm text-neutral-500">
                            <li v-for="item in ['Full competitor tracking', 'Weekly refreshed data', 'Gap Analysis', 'Optimal posting times']" :key="item" class="flex items-start gap-3">
                                <span class="mt-0.5 text-neutral-950">✓</span>
                                <span>{{ item }}</span>
                            </li>
                        </ul>
                        <div class="mt-8 border-t border-neutral-200 pt-6">
                            <a
                                :href="CTA_HREF"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex w-full items-center justify-center bg-[#F0C400] px-5 py-2.5 text-sm font-medium text-neutral-950 hover:opacity-90"
                            >
                                {{ CTA_TEXT }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-b border-neutral-200 bg-white">
            <div class="mx-auto max-w-3xl px-4 py-24">
                <h2 class="text-3xl font-semibold tracking-tight md:text-4xl">Questions, answered</h2>
                <div class="mt-10 divide-y divide-neutral-200 border-y border-neutral-200">
                    <details v-for="faq in faqs" :key="faq.q" class="group py-5">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                            <span class="text-base font-medium">{{ faq.q }}</span>
                            <span class="text-lg text-neutral-400 transition group-open:rotate-45">+</span>
                        </summary>
                        <p class="mt-3 text-sm leading-relaxed text-neutral-500">{{ faq.a }}</p>
                    </details>
                </div>
            </div>
        </section>

        <section class="pb-20">
            <div class="mx-auto max-w-4xl px-4 py-28 pb-10 text-center">
                <h2 class="text-3xl font-semibold tracking-tight md:text-5xl">
                    Open your Competitors' content playbook.
                </h2>
                <div class="mt-8 flex justify-center">
                    <a
                        :href="CTA_HREF"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center bg-[#F0C400] px-5 py-2.5 text-sm font-medium text-neutral-950 hover:opacity-90"
                    >
                        {{ CTA_TEXT }}
                    </a>
                </div>
            </div>
        </section>

        <div
            class="fixed inset-x-0 bottom-0 z-50 border-t border-neutral-200 bg-[#F0C400] transition-transform duration-300"
            :class="scrolled ? 'translate-y-0' : 'translate-y-full'"
        >
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
                <p class="text-sm text-neutral-950">
                    <span class="font-semibold">Snitch</span>
                    <span class="hidden opacity-80 sm:inline"> - The best kept secret in social media marketing.</span>
                </p>
                <a
                    :href="CTA_HREF"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center justify-center bg-neutral-950 px-4 py-2 text-xs font-medium text-white hover:opacity-90"
                >
                    {{ CTA_TEXT }}
                </a>
            </div>
        </div>
    </div>
</template>
