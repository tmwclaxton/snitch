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
    <div class="px-4 py-14 sm:px-8 sm:py-20">
        <article class="mx-auto max-w-3xl">
            <h1 class="text-4xl font-semibold tracking-tight text-neutral-950 sm:text-5xl">
                Built for people who ship content, or want to learn how.
            </h1>
            <div class="mt-8 space-y-5 text-base leading-relaxed text-neutral-600">
                <p>
                    Snitch is a personal tracker for public Instagram accounts.
                    Add rival handles, sync recent public posts into one place,
                    analyse what is working, and spot winners worth remaking.
                </p>
                <p>
                    It is for local brands, creators, and small agencies who
                    want a clear view of the Instagram gap - not another
                    multi-seat team suite. One workspace, prepaid usage by
                    plan, remake notes you can act on.
                </p>
                <p>
                    Inside the app you get Feed, Explore filters on hook and
                    craft terms, Brand context for onboarding, and a Winners
                    board. Out here we publish practical notes on the
                    <Link
                        :href="blog()"
                        class="font-medium text-neutral-950 underline decoration-[#F0C400]/70 underline-offset-2"
                        prefetch
                    >
                        Blog
                    </Link>
                    ,
                    <Link
                        :href="howItWorks()"
                        class="font-medium text-neutral-950 underline decoration-[#F0C400]/70 underline-offset-2"
                        prefetch
                    >
                        how it works
                    </Link>
                    , and
                    <Link
                        :href="pricing()"
                        class="font-medium text-neutral-950 underline decoration-[#F0C400]/70 underline-offset-2"
                        prefetch
                    >
                        pricing
                    </Link>
                    .
                </p>
            </div>
        </article>
    </div>
</template>
