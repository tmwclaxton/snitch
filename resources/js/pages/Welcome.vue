<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { login } from '@/routes';

defineOptions({
    layout: PublicLayout,
});

const CTA_TEXT = "Start tracking - it's free";

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
    <div class="bg-snitch-caution-ink text-snitch-caution-fog">
        <Head title="Better social media performance. Without manual research." />

        <!-- Caution Tape hero band -->
        <section class="flex min-h-[calc(100svh-3.5rem)] flex-col border-b border-white/10 bg-snitch-caution-ink">
            <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-4 py-10 text-center sm:py-12">
                <p class="mb-4 font-mono text-sm uppercase tracking-[0.18em] text-snitch-caution-yellow">
                    Snitch
                </p>
                <h1 class="snitch-hero-display text-[clamp(2.1rem,5.2vw,4.25rem)] text-snitch-caution-fog">
                    <span class="block">Better social media</span>
                    <span class="block">
                        <span class="bg-snitch-caution-yellow px-1.5 text-snitch-caution-ink">performance.</span>
                    </span>
                    <span class="mt-2 block text-[clamp(1.35rem,3.2vw,2.35rem)] tracking-wide text-snitch-caution-fog/90">
                        Without manual research.
                    </span>
                </h1>
                <p class="mx-auto mt-5 max-w-xl text-base text-snitch-caution-fog/80 md:text-lg">
                    Track your competitors and see the exact gap between you and them.
                    What's working, what you're missing, delivered instantly.
                </p>
                <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <Link
                        :href="login()"
                        class="inline-flex items-center justify-center bg-snitch-caution-yellow px-5 py-2.5 text-sm font-semibold uppercase tracking-wide text-snitch-caution-ink hover:opacity-90"
                    >
                        {{ CTA_TEXT }}
                    </Link>
                    <span class="snitch-alert-badge !bg-snitch-caution-pink">
                        Gap found
                    </span>
                </div>
            </div>

            <div class="border-t border-white/10 bg-black/40">
                <div class="mx-auto max-w-6xl px-4 py-4 text-center sm:py-5">
                    <p class="font-mono text-sm text-snitch-caution-fog/70">
                        Tracking <span class="font-bold text-snitch-caution-yellow">27</span> brands ·
                        <span class="font-bold text-snitch-caution-yellow">781</span> posts analysed
                    </p>
                </div>
            </div>
        </section>

        <section class="border-b border-snitch-caution-ink/15 bg-snitch-caution-yellow text-snitch-caution-ink">
            <div class="mx-auto max-w-6xl px-4 py-24">
                <h2
                    class="snitch-hero-display text-3xl tracking-tight md:text-4xl lg:whitespace-nowrap lg:text-[clamp(1.65rem,2.15vw,2.5rem)]"
                >
                    Stop scrolling your competitors. Let Snitch do it.
                </h2>
                <div class="mt-12 grid gap-8 md:grid-cols-4">
                    <div
                        v-for="(benefit, index) in benefits"
                        :key="benefit.title"
                        class="border border-snitch-caution-ink/15 bg-snitch-caution-ink p-6 text-snitch-caution-fog"
                    >
                        <div class="flex h-10 w-10 items-center justify-center bg-snitch-caution-yellow text-snitch-caution-ink">
                            <span class="font-mono text-sm font-bold">0{{ index + 1 }}</span>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold tracking-tight">{{ benefit.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-snitch-caution-fog/75">{{ benefit.body }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-b border-white/10 bg-snitch-caution-ink">
            <div class="mx-auto max-w-4xl px-4 py-24 text-center">
                <h2 class="snitch-hero-display text-2xl leading-snug tracking-tight text-snitch-caution-fog md:text-3xl">
                    Less than the cost of a single sponsored post,
                    for insight that shapes every post you make.
                </h2>
                <div class="relative mt-10 inline-block w-full max-w-md text-left">
                    <div class="border border-white/10 bg-[#141416] p-8">
                        <div class="flex items-baseline justify-between">
                            <h3 class="text-2xl font-semibold tracking-tight text-snitch-caution-fog">Platform</h3>
                            <p class="font-mono text-3xl font-bold tracking-tight text-snitch-caution-yellow">
                                £19<span class="text-base font-medium text-snitch-caution-fog/60">/mo</span>
                            </p>
                        </div>
                        <p class="mt-2 text-sm text-snitch-caution-fog/65">
                            Plus pay-as-you-go usage credits (£30 included each month).
                        </p>
                        <ul class="mt-6 space-y-3 text-sm text-snitch-caution-fog/70">
                            <li v-for="item in ['Full competitor tracking', 'Weekly refreshed data', 'Gap Analysis', 'Optimal posting times']" :key="item" class="flex items-start gap-3">
                                <span class="mt-0.5 text-snitch-caution-yellow">✓</span>
                                <span>{{ item }}</span>
                            </li>
                        </ul>
                        <div class="mt-8 border-t border-white/10 pt-6">
                            <Link
                                :href="login()"
                                class="inline-flex w-full items-center justify-center bg-snitch-caution-yellow px-5 py-2.5 text-sm font-semibold uppercase tracking-wide text-snitch-caution-ink hover:opacity-90"
                            >
                                {{ CTA_TEXT }}
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-b border-white/10 bg-[#121214]">
            <div class="mx-auto max-w-3xl px-4 py-24">
                <h2 class="snitch-hero-display text-3xl tracking-tight text-snitch-caution-fog md:text-4xl">Questions, answered</h2>
                <div class="mt-10 divide-y divide-white/10 border-y border-white/10">
                    <details v-for="faq in faqs" :key="faq.q" class="group py-5">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-snitch-caution-fog">
                            <span class="text-base font-medium">{{ faq.q }}</span>
                            <span class="text-lg text-snitch-caution-pink transition group-open:rotate-45">+</span>
                        </summary>
                        <p class="mt-3 text-sm leading-relaxed text-snitch-caution-fog/65">{{ faq.a }}</p>
                    </details>
                </div>
            </div>
        </section>

        <section class="bg-snitch-caution-yellow pb-20 text-snitch-caution-ink">
            <div class="mx-auto max-w-4xl px-4 py-28 pb-10 text-center">
                <h2 class="snitch-hero-display text-3xl tracking-tight md:text-5xl">
                    Open your Competitors' content playbook.
                </h2>
                <div class="mt-8 flex justify-center">
                    <Link
                        :href="login()"
                        class="inline-flex items-center justify-center bg-snitch-caution-ink px-5 py-2.5 text-sm font-semibold uppercase tracking-wide text-snitch-caution-yellow hover:opacity-90"
                    >
                        {{ CTA_TEXT }}
                    </Link>
                </div>
            </div>
        </section>

        <div
            class="fixed inset-x-0 bottom-0 z-50 border-t border-snitch-caution-ink/20 bg-snitch-caution-yellow text-snitch-caution-ink transition-transform duration-300"
            :class="scrolled ? 'translate-y-0' : 'translate-y-full'"
        >
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
                <p class="text-sm">
                    <span class="font-semibold uppercase tracking-wide">Snitch</span>
                    <span class="hidden opacity-80 sm:inline"> - The best kept secret in social media marketing.</span>
                </p>
                <Link
                    :href="login()"
                    class="inline-flex items-center justify-center bg-snitch-caution-ink px-4 py-2 text-xs font-semibold uppercase tracking-wide text-snitch-caution-yellow hover:opacity-90"
                >
                    {{ CTA_TEXT }}
                </Link>
            </div>
        </div>
    </div>
</template>
