<script setup lang="ts">
import { ArrowUpRight, Check, Plus } from '@lucide/vue';
import { onMounted, onUnmounted, ref } from 'vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import SnitchBrand from '@/components/SnitchBrand.vue';

/*
 * Standalone special landing page (snitchbeta copy).
 * Deliberately outside the shared public layout: no nav, no footer links,
 * no route helpers - nothing here links into the rest of the site. The
 * only exits are the Calendly CTA and the contact mailto.
 */

const CALENDLY_URL = 'https://calendly.com/dan-olympuslab/30min';
const CTA_TEXT = 'Book a free intro call';

const year = new Date().getFullYear();

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

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
});

onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
});
</script>

<template>
    <div class="snitch-surface relative min-h-screen w-full">
        <SeoHead
            title="Your market's Instagram insights, in one place"
            description="Snitch tracks your competitors' Instagram - follower growth, posting cadence, top posts and engagement - refreshed automatically every week. Free."
        />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 flex min-h-screen w-full flex-col">
            <!-- Brand only: intentionally not a link back into the site. -->
            <header class="border-b border-snitch-ink/10">
                <div
                    class="mx-auto flex w-full max-w-6xl items-center justify-between px-5 py-4 sm:px-8"
                >
                    <span class="text-snitch-ink">
                        <SnitchBrand size="nav" />
                    </span>
                </div>
            </header>

            <main class="w-full flex-1">
                <!-- Hero -->
                <section
                    class="relative overflow-hidden border-b border-snitch-ink/10 [background:radial-gradient(ellipse_70%_55%_at_50%_100%,color-mix(in_oklab,var(--snitch-spot)_16%,transparent),transparent_70%)]"
                >
                    <div
                        class="relative mx-auto flex max-w-6xl flex-col items-center px-5 py-20 text-center sm:px-8 sm:py-28"
                    >
                        <!-- Static mascot on mobile; desktop gets the peek. -->
                        <img
                            src="/images/marketing/hero/mascot-binos.png"
                            alt=""
                            width="196"
                            height="130"
                            decoding="async"
                            fetchpriority="high"
                            class="h-24 w-auto rotate-[-2deg] select-none [filter:drop-shadow(4px_4px_0_var(--snitch-spot))] md:hidden"
                        />

                        <div class="relative mt-10 w-full max-w-2xl md:mt-14">
                            <!--
                              Detective peek (same as the main landing hero):
                              slides up from behind the title card, binos raise
                              on a shorter loop. Hidden below md.
                            -->
                            <div
                                class="snitch-hero-mascot pointer-events-none absolute right-4 hidden md:block lg:right-7"
                                aria-hidden="true"
                            >
                                <div
                                    class="snitch-hero-mascot-peek origin-bottom"
                                >
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
                                <div
                                    class="snitch-hero-copy relative px-6 py-10 text-center sm:px-10 sm:py-12"
                                >
                                    <h1
                                        class="snitch-display text-[clamp(2.1rem,4.4vw,3.5rem)] leading-[1.06] tracking-[-0.02em] text-pretty text-snitch-ink"
                                    >
                                        <span class="relative block">
                                            <span
                                                class="pointer-events-none absolute inset-0 translate-x-[2px] translate-y-[1.5px] text-snitch-spot opacity-60 mix-blend-multiply select-none dark:mix-blend-plus-lighter dark:opacity-70"
                                                aria-hidden="true"
                                            >Better social media performance.</span>
                                            <span class="relative">Better social media performance.</span>
                                        </span>
                                        <span class="relative block">
                                            <span
                                                class="pointer-events-none absolute inset-0 translate-x-[2px] translate-y-[1.5px] text-snitch-spot opacity-60 mix-blend-multiply select-none dark:mix-blend-plus-lighter dark:opacity-70"
                                                aria-hidden="true"
                                            >Without manual research.</span>
                                            <span class="relative">Without manual research.</span>
                                        </span>
                                    </h1>
                                    <p
                                        class="mx-auto mt-6 max-w-xl text-base leading-relaxed text-snitch-ink/80 md:text-lg"
                                    >
                                        Track your competitors and see the
                                        exact gap between you and them. What's
                                        working, what you're missing, delivered
                                        instantly.
                                    </p>
                                    <div
                                        class="mt-8 flex justify-center sm:mt-10"
                                    >
                                        <a
                                            :href="CALENDLY_URL"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="snitch-btn snitch-btn-spot"
                                        >
                                            <span
                                                class="relative z-10 inline-flex items-center gap-2"
                                            >
                                                {{ CTA_TEXT }}
                                                <ArrowUpRight
                                                    class="size-3.5 shrink-0"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Social proof strip -->
                <section class="border-b border-snitch-ink/10 bg-snitch-fog/50">
                    <div
                        class="mx-auto max-w-6xl px-5 py-8 text-center sm:px-8"
                    >
                        <p class="text-sm text-snitch-ink/70">
                            Tracking
                            <span
                                class="snitch-annotation mx-1 align-middle text-2xl text-snitch-ink"
                            >27</span>
                            brands ·
                            <span
                                class="snitch-annotation mx-1 align-middle text-2xl text-snitch-ink"
                            >781</span>
                            posts analysed ·
                            <span
                                class="snitch-annotation mx-1 align-middle text-2xl text-snitch-ink"
                            >0</span>
                            Instagram logins required
                        </p>
                    </div>
                </section>

                <!-- Benefits -->
                <section class="border-b border-snitch-ink/10">
                    <div
                        class="mx-auto max-w-6xl px-5 py-20 sm:px-8 md:py-28"
                    >
                        <h2
                            class="snitch-display max-w-3xl text-4xl leading-tight text-snitch-ink md:text-5xl"
                        >
                            Stop scrolling your competitors. Let Snitch do it.
                        </h2>
                        <div
                            class="snitch-contact-reveal mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <article
                                v-for="(benefit, index) in benefits"
                                :key="benefit.title"
                                class="snitch-scrap relative p-6 pt-8"
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
                                <h3
                                    class="snitch-display mt-2 text-xl leading-tight text-snitch-ink"
                                >
                                    {{ benefit.title }}
                                </h3>
                                <p
                                    class="relative z-10 mt-2 text-sm leading-relaxed text-snitch-ink/80"
                                >
                                    {{ benefit.body }}
                                </p>
                            </article>
                        </div>
                    </div>
                </section>

                <!-- Pricing -->
                <section class="border-b border-snitch-ink/10 bg-snitch-fog/50">
                    <div
                        class="mx-auto max-w-4xl px-5 py-20 text-center sm:px-8 md:py-28"
                    >
                        <h2
                            class="snitch-display text-3xl leading-snug text-snitch-ink md:text-5xl"
                        >
                            Less than the cost of a single sponsored post,
                            <br class="hidden sm:block" />
                            for insight that shapes every post you make.
                        </h2>

                        <div
                            class="relative mt-12 inline-block w-full max-w-md text-left"
                        >
                            <div
                                class="snitch-scrap relative p-8 pt-10"
                            >
                                <span
                                    class="snitch-tape left-8 -top-2"
                                    aria-hidden="true"
                                />
                                <div
                                    class="relative z-10 flex items-baseline justify-between gap-4"
                                >
                                    <h3
                                        class="snitch-display text-3xl text-snitch-ink md:text-4xl"
                                    >
                                        Full Access
                                    </h3>
                                    <p
                                        class="snitch-display text-4xl text-snitch-ink"
                                    >
                                        £50<span
                                            class="text-base font-medium text-snitch-ink/60"
                                        >/mo</span>
                                    </p>
                                </div>

                                <ul
                                    class="relative z-10 mt-6 space-y-3 text-sm text-snitch-ink/80"
                                >
                                    <li
                                        v-for="feature in pricingFeatures"
                                        :key="feature"
                                        class="flex items-start gap-3"
                                    >
                                        <Check
                                            class="mt-0.5 size-4 shrink-0 text-snitch-ink"
                                            aria-hidden="true"
                                        />
                                        <span>{{ feature }}</span>
                                    </li>
                                </ul>

                                <div
                                    class="relative z-10 mt-8 border-t border-snitch-ink/10 pt-6"
                                >
                                    <a
                                        :href="CALENDLY_URL"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="snitch-btn snitch-btn-spot w-full"
                                    >
                                        <span
                                            class="relative z-10 inline-flex items-center gap-2"
                                        >
                                            {{ CTA_TEXT }}
                                            <ArrowUpRight
                                                class="size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                        </span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- FAQ -->
                <section class="border-b border-snitch-ink/10">
                    <div
                        class="mx-auto max-w-3xl px-5 py-20 sm:px-8 md:py-28"
                    >
                        <h2
                            class="snitch-display text-4xl text-snitch-ink md:text-5xl"
                        >
                            Questions, answered
                        </h2>
                        <div
                            class="mt-10 divide-y divide-snitch-ink/10 border-y border-snitch-ink/10"
                        >
                            <details
                                v-for="faq in faqs"
                                :key="faq.q"
                                class="group py-5"
                            >
                                <summary
                                    class="flex cursor-pointer list-none items-center justify-between gap-4"
                                >
                                    <span
                                        class="text-base font-semibold text-snitch-ink"
                                    >{{ faq.q }}</span>
                                    <Plus
                                        class="size-5 shrink-0 text-snitch-ink/55 transition-transform group-open:rotate-45"
                                        aria-hidden="true"
                                    />
                                </summary>
                                <p
                                    class="mt-3 text-sm leading-relaxed text-snitch-ink/80"
                                >
                                    {{ faq.a }}
                                </p>
                            </details>
                        </div>
                    </div>
                </section>

                <!-- Final CTA -->
                <section class="px-5 py-24 sm:px-8 md:py-28">
                    <div class="mx-auto max-w-4xl text-center">
                        <h2
                            class="snitch-display text-4xl text-snitch-ink md:text-6xl"
                        >
                            Open your Competitors' content playbook.
                        </h2>
                        <div class="mt-8 flex justify-center">
                            <a
                                :href="CALENDLY_URL"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="snitch-btn snitch-btn-spot"
                            >
                                <span
                                    class="relative z-10 inline-flex items-center gap-2"
                                >
                                    {{ CTA_TEXT }}
                                    <ArrowUpRight
                                        class="size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                </span>
                            </a>
                        </div>
                    </div>
                </section>
            </main>

            <!-- Minimal footer: no links into the rest of the site. -->
            <footer class="relative z-10 border-t border-snitch-ink/10">
                <div
                    class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-5 pt-8 pb-24 text-xs text-snitch-ink/55 sm:flex-row sm:px-8"
                >
                    <p>© {{ year }} Snitch</p>
                    <a
                        href="mailto:snitch377@gmail.com"
                        class="underline decoration-snitch-ink/30 underline-offset-2 transition-colors hover:text-snitch-ink"
                    >Contact</a>
                </div>
            </footer>
        </div>

        <!-- Sticky CTA bar -->
        <div
            class="fixed inset-x-0 bottom-0 z-50 border-t border-snitch-press bg-snitch-press text-snitch-stock transition-transform duration-300"
            :class="scrolled ? 'translate-y-0' : 'translate-y-full'"
        >
            <div
                class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3"
            >
                <p class="min-w-0 text-sm">
                    <span class="snitch-display text-lg">Snitch</span>
                    <span class="hidden opacity-80 sm:inline">
                        - The best kept secret in social media marketing.</span>
                </p>
                <a
                    :href="CALENDLY_URL"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="snitch-btn snitch-btn-spot shrink-0 px-3 py-2 text-xs"
                >
                    <span class="relative z-10 inline-flex items-center gap-2">
                        {{ CTA_TEXT }}
                        <ArrowUpRight
                            class="size-3 shrink-0"
                            aria-hidden="true"
                        />
                    </span>
                </a>
            </div>
        </div>
    </div>
</template>
