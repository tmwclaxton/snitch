<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { howItWorks, pricing } from '@/routes';
import { index as blog } from '@/routes/blog';

defineOptions({
    layout: PublicLayout,
});
</script>

<template>
    <div>
        <div class="px-5 py-14 sm:px-8 sm:py-20">
            <article class="snitch-doc relative mx-auto max-w-3xl px-6 py-10 sm:px-10">
                <h1
                    class="snitch-display relative z-10 text-4xl text-snitch-ink sm:text-5xl"
                >
                    Built for people who ship content, or want to learn how.
                </h1>
                <div
                    class="relative z-10 mt-8 space-y-5 text-base leading-relaxed text-snitch-ink/80"
                >
                    <p>
                        Snitch is a personal competitor tracker for public short
                        video. Add rival handles on TikTok, Instagram, YouTube
                        Shorts, Facebook, and LinkedIn. We sync recent public
                        reels into one contact sheet, analyse the craft, and
                        score winners with rules you control.
                    </p>
                    <p>
                        It is for local brands, creators, and small agencies who
                        want a clear tear sheet of what is working - not another
                        multi-seat team suite. One workspace, competitor caps by
                        plan, remake notes you can act on.
                    </p>
                    <p>
                        Inside the app you get Feed, Explore filters on hook and
                        craft terms, Brand context for onboarding, and a Winners
                        board. Out here we publish practical notes on the
                        <Link
                            :href="blog()"
                            class="font-medium text-snitch-ink underline decoration-snitch-ink/30 underline-offset-2"
                            prefetch
                        >
                            Blog
                        </Link>
                        ,
                        <Link
                            :href="howItWorks()"
                            class="font-medium text-snitch-ink underline decoration-snitch-ink/30 underline-offset-2"
                            prefetch
                        >
                            how it works
                        </Link>
                        , and
                        <Link
                            :href="pricing()"
                            class="font-medium text-snitch-ink underline decoration-snitch-ink/30 underline-offset-2"
                            prefetch
                        >
                            pricing
                        </Link>
                        .
                    </p>
                </div>
            </article>
        </div>
    </div>
</template>
