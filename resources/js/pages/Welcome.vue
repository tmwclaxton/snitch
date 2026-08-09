<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, LayoutGrid, LogIn, UserPlus } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { dashboard, login } from '@/routes';

defineOptions({
    layout: PublicLayout,
});

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const primaryCta = computed(() => {
    if (isAuthenticated.value) {
        return {
            href: dashboard(),
            label: 'Open dashboard',
        };
    }

    return {
        href: login(),
        label: 'Get started',
    };
});

/** Hero wall + marquee layers are large; hide until decoded to avoid progressive paint strips. */
const heroBackdropSources = [
    '/images/marketing/hero/bg.jpg',
    '/images/marketing/hero/platforms-back.png',
    '/images/marketing/hero/platforms-mid.png',
    '/images/marketing/hero/platforms-front.png',
] as const;

const heroBackdropReady = ref(false);

function preloadHeroImage(src: string): Promise<void> {
    return new Promise((resolve) => {
        const image = new Image();
        image.decoding = 'async';
        image.onload = () => {
            if (typeof image.decode === 'function') {
                void image
                    .decode()
                    .then(() => resolve())
                    .catch(() => resolve());

                return;
            }

            resolve();
        };
        image.onerror = () => resolve();
        image.src = src;
    });
}

onMounted(() => {
    void Promise.all(heroBackdropSources.map((src) => preloadHeroImage(src))).then(
        () => {
            heroBackdropReady.value = true;
        },
    );
});

const platforms = [
    { name: 'TikTok', slug: 'tiktok' },
    { name: 'Instagram', slug: 'instagram' },
    { name: 'YouTube', slug: 'youtube' },
    { name: 'Facebook', slug: 'facebook' },
    { name: 'LinkedIn', slug: 'linkedin' },
] as const;

const steps = [
    {
        stamp: '01',
        title: 'Track',
        copy: 'Add competitor accounts. Snitch pulls recent public posts into one contact sheet.',
    },
    {
        stamp: '02',
        title: 'Analyze',
        copy: 'Full-video analysis surfaces the hook, visuals, SFX, and the idea behind each post.',
    },
    {
        stamp: '03',
        title: 'Win',
        copy: 'Your rules score Winners - posts worth remaking, with notes on how to copy.',
    },
];
</script>

<template>
    <div>
        <!-- Nav overlays this section (absolute snitch-nav-hero); together = one dvh. -->
        <section
            class="snitch-hero relative h-dvh w-full overflow-hidden"
        >
            <div class="absolute inset-0" aria-hidden="true">
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
                                    class="snitch-hero-marquee-frame opacity-[0.72] mix-blend-multiply dark:mix-blend-soft-light dark:opacity-80"
                                    width="1792"
                                    height="1024"
                                    decoding="async"
                                />
                                <img
                                    src="/images/marketing/hero/platforms-back.png"
                                    alt=""
                                    class="snitch-hero-marquee-frame opacity-[0.72] mix-blend-multiply dark:mix-blend-soft-light dark:opacity-80"
                                    width="1792"
                                    height="1024"
                                    decoding="async"
                                />
                            </div>
                        </div>

                        <div
                            class="snitch-hero-marquee snitch-hero-marquee-mid absolute inset-0"
                        >
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
                <div class="snitch-grain z-[3] opacity-25" />
            </div>

            <!-- Same content column as PublicNav: max-w-6xl + px-5 / sm:px-8 -->
            <div
                class="relative z-10 mx-auto flex h-full w-full max-w-6xl items-end px-5 pb-8 pt-28 sm:px-8 sm:pb-10"
            >
                <div class="relative min-w-0 max-w-md sm:max-w-lg">
                    <!-- Peek mascot sits outside the yellow drop-shadow filter. -->
                    <div
                        class="snitch-hero-mascot pointer-events-none absolute right-4 hidden md:block lg:right-7"
                        aria-hidden="true"
                    >
                        <div class="snitch-hero-mascot-peek origin-bottom">
                            <div class="snitch-hero-mascot-frame relative select-none overflow-hidden">
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
                            class="snitch-hero-copy relative px-5 pb-5 pt-5 text-left sm:px-7 sm:pb-6 sm:pt-6"
                        >
                            <p
                                class="snitch-display snitch-hero-wordmark relative text-[clamp(4.75rem,14.5vw,8.5rem)] leading-[0.78] tracking-[-0.035em] text-snitch-ink"
                            >
                                <span
                                    class="snitch-hero-wordmark-misreg pointer-events-none absolute inset-0 select-none"
                                    aria-hidden="true"
                                >Snitch</span>
                                <span class="relative">Snitch</span>
                            </p>
                            <h1
                                class="snitch-display snitch-hero-lede mt-3.5 text-[1.3rem] leading-[1.22] tracking-[-0.012em] text-pretty sm:mt-4 sm:text-[1.55rem] sm:leading-[1.2]"
                            >
                                See what competitors post. Remake what wins.
                            </h1>
                            <div
                                class="snitch-hero-cta mt-6 flex flex-wrap items-stretch justify-start gap-2.5 sm:mt-7 sm:gap-3"
                            >
                                <Link
                                    v-if="isAuthenticated"
                                    :href="dashboard()"
                                    class="snitch-btn snitch-btn-spot"
                                >
                                    <span class="relative z-10 inline-flex items-center gap-2">
                                        <LayoutGrid class="size-3.5 shrink-0" aria-hidden="true" />
                                        Dashboard
                                    </span>
                                </Link>
                                <template v-else>
                                    <Link :href="login()" class="snitch-btn">
                                        <span class="relative z-10 inline-flex items-center gap-2">
                                            <LogIn class="size-3.5 shrink-0" aria-hidden="true" />
                                            Log in
                                        </span>
                                    </Link>
                                    <Link
                                        :href="login()"
                                        class="snitch-btn snitch-btn-spot"
                                    >
                                        <span class="relative z-10 inline-flex items-center gap-2">
                                            <UserPlus class="size-3.5 shrink-0" aria-hidden="true" />
                                            Sign up
                                        </span>
                                    </Link>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="relative px-5 py-20 sm:px-8 sm:py-24">
            <div class="mx-auto max-w-6xl">
                <h2
                    class="snitch-display max-w-5xl text-pretty text-3xl text-snitch-ink sm:text-4xl"
                >
                    Competitor social intelligence with a soft print brain.
                </h2>
                <p class="mt-4 max-w-3xl text-base leading-relaxed text-snitch-ink/80">
                    Built for local brands, creators, and agencies who need the
                    signal - not another purple dashboard.
                </p>
            </div>
        </section>

        <section class="px-5 pb-20 sm:px-8">
            <div class="mx-auto max-w-6xl">
                <h2 class="snitch-display text-3xl text-snitch-ink">
                    How it works
                </h2>
                <div
                    class="snitch-contact-reveal mt-10 grid gap-6 md:grid-cols-3"
                >
                    <article
                        v-for="step in steps"
                        :key="step.stamp"
                        class="snitch-scrap relative p-6 pt-8"
                    >
                        <span
                            class="snitch-tape left-5 -top-2"
                            aria-hidden="true"
                        />
                        <p
                            class="snitch-annotation text-3xl font-medium text-snitch-ink"
                        >
                            {{ step.stamp }}
                        </p>
                        <h3 class="snitch-display mt-2 text-2xl text-snitch-ink">
                            <span class="snitch-marker-underline">{{
                                step.title
                            }}</span>
                        </h3>
                        <p class="relative z-10 mt-3 text-sm leading-relaxed text-snitch-ink/80">
                            {{ step.copy }}
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section class="px-5 pb-20 sm:px-8">
            <div class="mx-auto max-w-6xl">
                <h2 class="snitch-display text-3xl text-snitch-ink">
                    Platforms
                </h2>
                <p class="mt-3 max-w-3xl text-snitch-ink/80">
                    One feed for the public posts that matter.
                </p>
                <ul
                    class="mt-8 flex flex-wrap items-end gap-x-8 gap-y-6 sm:gap-x-12"
                    aria-label="Supported platforms"
                >
                    <li
                        v-for="(platform, index) in platforms"
                        :key="platform.slug"
                        class="flex flex-col items-center gap-2"
                        :style="{
                            transform: `rotate(${index % 2 === 0 ? -1.5 : 1.2}deg)`,
                        }"
                    >
                        <img
                            :src="`/images/platforms/${platform.slug}.svg`"
                            :alt="`${platform.name} logo`"
                            class="snitch-platform-logo h-10 w-10 sm:h-12 sm:w-12"
                            width="48"
                            height="48"
                            loading="lazy"
                        />
                        <span class="snitch-annotation text-lg text-snitch-ink/75">
                            {{ platform.name }}
                        </span>
                    </li>
                </ul>
            </div>
        </section>

        <section class="px-5 pb-20 sm:px-8">
            <div
                class="snitch-tear-board relative mx-auto max-w-6xl overflow-hidden px-6 py-12 sm:px-10"
            >
                <span class="snitch-tape left-8 -top-1" aria-hidden="true" />
                <h2
                    class="snitch-display relative z-10 mt-2 max-w-3xl text-3xl text-snitch-ink"
                >
                    The posts that earn a remake.
                </h2>
                <p class="relative z-10 mt-3 max-w-3xl text-snitch-ink/80">
                    You set the bar. We score what cleared it, why it won, and
                    how to steal the craft.
                </p>
            </div>
        </section>

        <section class="px-5 pb-24 sm:px-8">
            <div class="mx-auto max-w-6xl text-center">
                <h2 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                    Start tracking the competition.
                </h2>
                <p class="mx-auto mt-3 max-w-2xl text-snitch-ink/80">
                    Sign in and build your first competitor list.
                </p>
                <div class="mt-8 flex justify-center">
                    <Link
                        :href="primaryCta.href"
                        class="snitch-btn snitch-btn-spot"
                    >
                        <span class="relative z-10 inline-flex items-center gap-2">
                            <LayoutGrid
                                v-if="isAuthenticated"
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <ArrowRight
                                v-else
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            {{ primaryCta.label }}
                        </span>
                    </Link>
                </div>
            </div>
        </section>
    </div>
</template>
