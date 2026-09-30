<script setup lang="ts">
import { ArrowUpRight, Check, Plus } from '@lucide/vue';
import { onMounted, onUnmounted, ref } from 'vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

/*
 * Special landing page with Calendly CTA. Shares PublicLayout (white chrome,
 * shared nav/footer with hello@snitchsocial.net). Hero keeps the mascot peek
 * + sliding platform wall animation from the original beta landing.
 */

defineOptions({
    layout: PublicLayout,
});

const CALENDLY_URL = 'https://calendly.com/dan-olympuslab/30min';
const CTA_TEXT = 'Book a free intro call';

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
        body: "Follower trends, posting cadence and engagement. Tracked weekly so shifts in your market surface before they're obvious.",
    },
    {
        title: 'See their ad spend in action',
        body: "Track which posts they're boosting, what the creative looks like, and how long each campaign runs before you spend a penny.",
    },
] as const;

const pricingFeatures = [
    '£19/mo platform plus pay-as-you-go usage credits',
    '£30 usage credits included each billing period',
    'Full competitor tracking',
    'Weekly refreshed data',
    'Gap Analysis',
    'Optimal posting times',
] as const;

const faqs = [
    {
        q: 'Do I need to connect my Instagram account?',
        a: 'No. Snitch only reads public Instagram data on the competitors you add. Your own account is never connected, logged into or posted from.',
    },
    {
        q: 'How often does the data refresh?',
        a: "Every week, automatically. Add a competitor and they'll be pulled in the next weekly refresh - no manual re-runs needed.",
    },
    {
        q: 'How long does setup take?',
        a: "About 60 seconds. Sign up, paste the Instagram handles you want to watch, and you're done. Your first report lands after the next weekly pull.",
    },
] as const;

const scrolled = ref(false);

const onScroll = (): void => {
    scrolled.value = window.scrollY > 400;
};

/*
 * Hero wall + marquee layers are large; hide until decoded to avoid
 * progressive paint strips. Desktop only - mobile keeps a simple wash.
 */
const heroBackdropSources = [
    '/images/marketing/hero/bg.jpg',
    '/images/marketing/hero/platforms-back.png',
    '/images/marketing/hero/platforms-mid.png',
    '/images/marketing/hero/platforms-front.png',
] as const;

const heroBackdropReady = ref(false);
const desktopHeroArt = ref(false);

function preloadHeroImage(src: string): Promise<void> {
    return new Promise((resolve) => {
        const image = new Image();
        image.onload = () => resolve();
        image.onerror = () => resolve();
        image.src = src;
    });
}

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    const desktopQuery = window.matchMedia('(min-width: 768px)');

    const syncDesktopHero = (): void => {
        if (!desktopQuery.matches) {
            return;
        }

        desktopHeroArt.value = true;

        if (heroBackdropReady.value) {
            return;
        }

        void Promise.all(
            heroBackdropSources.map((src) => preloadHeroImage(src)),
        ).then(() => {
            heroBackdropReady.value = true;
        });
    };

    syncDesktopHero();
    desktopQuery.addEventListener('change', syncDesktopHero);

    onUnmounted(() => {
        window.removeEventListener('scroll', onScroll);
        desktopQuery.removeEventListener('change', syncDesktopHero);
    });
});
</script>

<template>
    <div class="snitch-light bg-white text-neutral-950">
        <main>
            <section class="snitch-hero relative overflow-hidden border-b border-neutral-200">
                <div
                    v-if="desktopHeroArt"
                    class="absolute inset-0 hidden md:block"
                    aria-hidden="true"
                >
                    <div class="snitch-hero-backdrop-placeholder" />
                    <div
                        class="snitch-hero-backdrop"
                        :class="{ 'is-ready': heroBackdropReady }"
                    >
                        <div class="snitch-hero-bg">
                            <img
                                src="/images/marketing/hero/bg.jpg"
                                alt=""
                                class="snitch-hero-bg-img"
                                width="1792"
                                height="1024"
                                decoding="async"
                                fetchpriority="high"
                            />
                        </div>

                        <div class="snitch-hero-marquee-stage">
                            <div
                                class="snitch-hero-marquee snitch-hero-marquee-slow absolute inset-x-0 top-[2%] bottom-0"
                            >
                                <div class="snitch-hero-marquee-track">
                                    <img
                                        src="/images/marketing/hero/platforms-back.png"
                                        alt=""
                                        class="snitch-hero-marquee-frame opacity-[0.72]"
                                        width="1792"
                                        height="1024"
                                        decoding="async"
                                    />
                                    <img
                                        src="/images/marketing/hero/platforms-back.png"
                                        alt=""
                                        class="snitch-hero-marquee-frame opacity-[0.72]"
                                        width="1792"
                                        height="1024"
                                        decoding="async"
                                    />
                                </div>
                            </div>

                            <div class="snitch-hero-marquee snitch-hero-marquee-mid absolute inset-0">
                                <div class="snitch-hero-marquee-track">
                                    <img
                                        src="/images/marketing/hero/platforms-mid.png"
                                        alt=""
                                        class="snitch-hero-marquee-frame opacity-[0.88]"
                                        width="1792"
                                        height="1024"
                                        decoding="async"
                                    />
                                    <img
                                        src="/images/marketing/hero/platforms-mid.png"
                                        alt=""
                                        class="snitch-hero-marquee-frame opacity-[0.88]"
                                        width="1792"
                                        height="1024"
                                        decoding="async"
                                    />
                                </div>
                            </div>

                            <div
                                class="snitch-hero-marquee snitch-hero-marquee-fast absolute inset-x-0 top-[-2%] bottom-0"
                            >
                                <div class="snitch-hero-marquee-track">
                                    <img
                                        src="/images/marketing/hero/platforms-front.png"
                                        alt=""
                                        class="snitch-hero-marquee-frame opacity-95"
                                        width="1792"
                                        height="1024"
                                        decoding="async"
                                    />
                                    <img
                                        src="/images/marketing/hero/platforms-front.png"
                                        alt=""
                                        class="snitch-hero-marquee-frame opacity-95"
                                        width="1792"
                                        height="1024"
                                        decoding="async"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="snitch-hero-scrim absolute inset-0 z-[2]" />
                </div>

                <div
                    class="relative z-10 mx-auto flex max-w-6xl flex-col items-center px-5 py-20 text-center sm:px-8 sm:py-28"
                >
                    <img
                        src="/images/marketing/hero/mascot-binos.png"
                        alt=""
                        width="196"
                        height="130"
                        decoding="async"
                        fetchpriority="high"
                        class="h-24 w-auto rotate-[-2deg] select-none md:hidden"
                    />

                    <div class="relative mt-10 w-full max-w-2xl md:mt-14">
                        <div
                            class="snitch-hero-mascot pointer-events-none absolute right-4 hidden md:block lg:right-7"
                            aria-hidden="true"
                        >
                            <div class="snitch-hero-mascot-peek origin-bottom">
                                <div
                                    class="snitch-hero-mascot-frame relative select-none overflow-hidden"
                                >
                                    <img
                                        src="/images/marketing/hero/mascot-character.png"
                                        alt=""
                                        draggable="false"
                                        class="snitch-hero-mascot-character absolute inset-0 h-full w-full object-contain"
                                        width="140"
                                        height="140"
                                        decoding="async"
                                    />
                                    <img
                                        src="/images/marketing/hero/mascot-binos.png"
                                        alt=""
                                        draggable="false"
                                        class="snitch-hero-mascot-binos absolute left-1/2"
                                        width="98"
                                        height="65"
                                        decoding="async"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="snitch-hero-copy-shell relative z-[5]">
                            <div class="snitch-hero-copy relative px-6 py-10 text-center sm:px-10 sm:py-12">
                                <h1
                                    class="text-[clamp(2.1rem,4.4vw,3.5rem)] font-semibold leading-[1.06] tracking-tight text-pretty text-neutral-950"
                                >
                                    <span class="block">Better social media performance.</span>
                                    <span class="block">Without manual research.</span>
                                </h1>
                                <p
                                    class="mx-auto mt-6 max-w-xl text-base leading-relaxed text-neutral-600 md:text-lg"
                                >
                                    Track your competitors and see the exact gap between you and them.
                                    What's working, what you're missing, delivered instantly.
                                </p>
                                <div class="mt-8 flex justify-center sm:mt-10">
                                    <a
                                        :href="CALENDLY_URL"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center justify-center gap-2 bg-[#F0C400] px-5 py-2.5 text-sm font-medium text-neutral-950 hover:opacity-90"
                                    >
                                        {{ CTA_TEXT }}
                                        <ArrowUpRight class="size-3.5 shrink-0" aria-hidden="true" />
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-b border-neutral-200 bg-neutral-50">
                <div class="mx-auto max-w-6xl px-5 py-8 text-center sm:px-8">
                    <p class="text-sm text-neutral-500">
                        Tracking
                        <span class="font-semibold text-neutral-950">27</span>
                        brands ·
                        <span class="font-semibold text-neutral-950">781</span>
                        posts analysed
                    </p>
                </div>
            </section>

            <section class="border-b border-neutral-200">
                <div class="mx-auto max-w-6xl px-5 py-20 sm:px-8 md:py-28">
                    <h2 class="text-3xl font-semibold tracking-tight text-neutral-950 md:text-4xl lg:whitespace-nowrap lg:text-[clamp(1.65rem,2.15vw,2.5rem)]">
                        Stop scrolling your competitors. Let Snitch do it.
                    </h2>
                    <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        <article
                            v-for="(benefit, index) in benefits"
                            :key="benefit.title"
                            class="border border-neutral-200 bg-white p-6"
                        >
                            <p class="text-3xl font-semibold text-neutral-950">
                                0{{ index + 1 }}
                            </p>
                            <h3 class="mt-2 text-xl font-semibold tracking-tight text-neutral-950">
                                {{ benefit.title }}
                            </h3>
                            <p class="mt-2 text-sm leading-relaxed text-neutral-600">
                                {{ benefit.body }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <section class="border-b border-neutral-200 bg-neutral-50">
                <div class="mx-auto max-w-4xl px-5 py-20 text-center sm:px-8 md:py-28">
                    <h2 class="text-3xl font-semibold tracking-tight text-neutral-950 md:text-4xl">
                        Less than the cost of a single sponsored post,
                        <br class="hidden sm:block" />
                        for insight that shapes every post you make.
                    </h2>

                    <div class="relative mt-12 inline-block w-full max-w-md text-left">
                        <div class="border border-neutral-200 bg-white p-8">
                            <div class="flex items-baseline justify-between gap-4">
                                <h3 class="text-3xl font-semibold tracking-tight text-neutral-950 md:text-4xl">
                                    Platform
                                </h3>
                                <p class="text-4xl font-semibold tracking-tight text-neutral-950">
                                    £19<span class="text-base font-medium text-neutral-500">/mo</span>
                                </p>
                            </div>

                            <ul class="mt-6 space-y-3 text-sm text-neutral-600">
                                <li
                                    v-for="feature in pricingFeatures"
                                    :key="feature"
                                    class="flex items-start gap-3"
                                >
                                    <Check
                                        class="mt-0.5 size-4 shrink-0 text-neutral-950"
                                        aria-hidden="true"
                                    />
                                    <span>{{ feature }}</span>
                                </li>
                            </ul>

                            <div class="mt-8 border-t border-neutral-200 pt-6">
                                <a
                                    :href="CALENDLY_URL"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex w-full items-center justify-center gap-2 bg-[#F0C400] px-5 py-2.5 text-sm font-medium text-neutral-950 hover:opacity-90"
                                >
                                    {{ CTA_TEXT }}
                                    <ArrowUpRight class="size-3.5 shrink-0" aria-hidden="true" />
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-b border-neutral-200">
                <div class="mx-auto max-w-3xl px-5 py-20 sm:px-8 md:py-28">
                    <h2 class="text-3xl font-semibold tracking-tight text-neutral-950 md:text-4xl">
                        Questions, answered
                    </h2>
                    <div class="mt-10 divide-y divide-neutral-200 border-y border-neutral-200">
                        <details
                            v-for="faq in faqs"
                            :key="faq.q"
                            class="group py-5"
                        >
                            <summary
                                class="flex cursor-pointer list-none items-center justify-between gap-4"
                            >
                                <span class="text-base font-semibold text-neutral-950">{{ faq.q }}</span>
                                <Plus
                                    class="size-5 shrink-0 text-neutral-500 transition-transform group-open:rotate-45"
                                    aria-hidden="true"
                                />
                            </summary>
                            <p class="mt-3 text-sm leading-relaxed text-neutral-600">
                                {{ faq.a }}
                            </p>
                        </details>
                    </div>
                </div>
            </section>

            <section class="px-5 py-24 sm:px-8 md:py-28">
                <div class="mx-auto max-w-4xl text-center">
                    <h2 class="text-3xl font-semibold tracking-tight text-neutral-950 md:text-5xl">
                        Open your Competitors' content playbook.
                    </h2>
                    <div class="mt-8 flex justify-center">
                        <a
                            :href="CALENDLY_URL"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center justify-center gap-2 bg-[#F0C400] px-5 py-2.5 text-sm font-medium text-neutral-950 hover:opacity-90"
                        >
                            {{ CTA_TEXT }}
                            <ArrowUpRight class="size-3.5 shrink-0" aria-hidden="true" />
                        </a>
                    </div>
                </div>
            </section>
        </main>

        <div
            class="fixed inset-x-0 bottom-0 z-50 border-t border-neutral-950 bg-neutral-950 text-white transition-transform duration-300"
            :class="scrolled ? 'translate-y-0' : 'translate-y-full'"
        >
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
                <p class="min-w-0 text-sm">
                    <span class="text-lg font-semibold">Snitch</span>
                    <span class="hidden opacity-80 sm:inline">
                        - The best kept secret in social media marketing.</span>
                </p>
                <a
                    :href="CALENDLY_URL"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex shrink-0 items-center gap-2 bg-[#F0C400] px-3 py-2 text-xs font-medium text-neutral-950 hover:opacity-90"
                >
                    {{ CTA_TEXT }}
                    <ArrowUpRight class="size-3 shrink-0" aria-hidden="true" />
                </a>
            </div>
        </div>
    </div>
</template>
