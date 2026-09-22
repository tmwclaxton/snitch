<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, LayoutGrid, LogIn, UserPlus } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
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

const heroEl = ref<HTMLElement | null>(null);

/**
 * Browser chrome makes 100dvh unreliable on phones. Measure once and lock - do
 * not follow visualViewport/URL-bar changes while scrolling (that resizes the
 * poster mid-scroll and feels jarring). Desktop uses the same poster; width
 * changes and orientation still remeasure.
 */
function measureVisibleViewportHeight(): number {
    const visualHeight = window.visualViewport?.height;
    const layoutHeight = window.innerHeight;

    if (typeof visualHeight === 'number' && visualHeight > 0) {
        return Math.round(Math.min(visualHeight, layoutHeight));
    }

    return Math.round(layoutHeight);
}

onMounted(() => {
    let lockedHeroHeight = 0;
    let lastViewportWidth = window.innerWidth;

    const applyHeroHeight = (force = false): void => {
        const el = heroEl.value;

        if (!el) {
            return;
        }

        if (!force && lockedHeroHeight > 0) {
            return;
        }

        const height = measureVisibleViewportHeight();

        if (height <= 0) {
            return;
        }

        lockedHeroHeight = height;
        el.style.setProperty('--snitch-mobile-hero-height', `${height}px`);
    };

    const onViewportWidthChange = (): void => {
        const width = window.innerWidth;

        // Ignore height-only resizes from mobile browser chrome show/hide.
        if (Math.abs(width - lastViewportWidth) < 2) {
            return;
        }

        lastViewportWidth = width;
        applyHeroHeight(true);
    };

    const onOrientationChange = (): void => {
        window.setTimeout(() => {
            lastViewportWidth = window.innerWidth;
            applyHeroHeight(true);
        }, 250);
    };

    applyHeroHeight(true);

    window.addEventListener('resize', onViewportWidthChange);
    window.addEventListener('orientationchange', onOrientationChange);

    onUnmounted(() => {
        window.removeEventListener('resize', onViewportWidthChange);
        window.removeEventListener('orientationchange', onOrientationChange);
        heroEl.value?.style.removeProperty('--snitch-mobile-hero-height');
    });
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
        copy: 'Add snitch accounts. Snitch pulls recent public posts into one contact sheet.',
    },
    {
        stamp: '02',
        title: 'Analyse',
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
        <!--
          One poster hero at every breakpoint: vertical riso print with the
          mascot as the visual anchor (same composition that works on mobile).
        -->
        <section
            ref="heroEl"
            class="snitch-hero-mobile relative w-full overflow-hidden"
            aria-label="Snitch"
        >
            <div class="absolute inset-0" aria-hidden="true">
                <div class="snitch-hero-mobile-wash" />
                <div class="snitch-grain z-[1] opacity-30" />
            </div>

            <!-- Floor over mascot: mustard ink band covers the feet. -->
            <div class="snitch-hero-mobile-stage" aria-hidden="true">
                <div class="snitch-hero-mobile-floor" />
                <div class="snitch-hero-mobile-mascot">
                    <div class="snitch-hero-mobile-mascot-bob origin-bottom">
                        <div
                            class="snitch-hero-mobile-mascot-frame relative mx-auto select-none overflow-hidden"
                        >
                            <img
                                src="/images/marketing/hero/mascot-character.png"
                                alt=""
                                draggable="false"
                                class="snitch-hero-mascot-character absolute inset-0 h-full w-full object-contain object-bottom"
                                width="280"
                                height="200"
                                decoding="async"
                                fetchpriority="high"
                            />
                            <img
                                src="/images/marketing/hero/mascot-binos.png"
                                alt=""
                                draggable="false"
                                class="snitch-hero-mascot-binos absolute left-1/2"
                                width="196"
                                height="130"
                                decoding="async"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative z-10 mx-auto w-full max-w-6xl px-5 sm:px-8">
                <div class="snitch-hero-mobile-copy">
                    <p
                        class="snitch-display snitch-hero-mobile-wordmark relative text-[clamp(3.2rem,15vw,4.75rem)] leading-[0.8] tracking-[-0.04em] text-snitch-ink md:text-[clamp(4.5rem,9vw,7rem)]"
                    >
                        <span
                            class="snitch-hero-wordmark-misreg pointer-events-none absolute inset-0 select-none"
                            aria-hidden="true"
                        >Snitch</span>
                        <span class="relative">Snitch</span>
                    </p>
                    <h1
                        class="snitch-display mt-3 max-w-[16rem] text-[1.15rem] leading-[1.25] tracking-[-0.012em] text-pretty text-snitch-ink md:mt-4 md:max-w-md md:text-[1.45rem] md:leading-[1.22]"
                    >
                        See what competitors post. Remake what wins.
                    </h1>
                    <div
                        class="snitch-hero-mobile-cta mt-5 flex flex-col items-stretch gap-2.5"
                    >
                        <Link
                            v-if="isAuthenticated"
                            :href="dashboard()"
                            class="snitch-btn snitch-btn-spot w-full justify-center"
                        >
                            <span class="relative z-10 inline-flex items-center gap-2">
                                <LayoutGrid class="size-3.5 shrink-0" aria-hidden="true" />
                                Open dashboard
                            </span>
                        </Link>
                        <template v-else>
                            <Link
                                :href="login()"
                                class="snitch-btn snitch-btn-spot w-full justify-center"
                            >
                                <span class="relative z-10 inline-flex items-center gap-2">
                                    <UserPlus class="size-3.5 shrink-0" aria-hidden="true" />
                                    Get started
                                </span>
                            </Link>
                            <Link
                                :href="login()"
                                class="snitch-btn w-full justify-center"
                            >
                                <span class="relative z-10 inline-flex items-center gap-2">
                                    <LogIn class="size-3.5 shrink-0" aria-hidden="true" />
                                    Log in
                                </span>
                            </Link>
                        </template>
                    </div>
                </div>
            </div>
        </section>

        <section class="relative px-5 py-20 sm:px-8 sm:py-24">
            <div class="mx-auto max-w-6xl">
                <h2
                    class="snitch-display max-w-5xl text-pretty text-3xl text-snitch-ink sm:text-4xl"
                >
                    Competitor social intel
                </h2>
                <p class="mt-4 max-w-3xl text-base leading-relaxed text-snitch-ink/80">
                    Built for social marketers who need to know what rivals post, when they post, and what lands.
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
                    Sign in and build your first snitch list.
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
