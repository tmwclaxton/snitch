<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import SeoHead from '@/components/marketing/SeoHead.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { home, login } from '@/routes';

defineOptions({
    layout: PublicLayout,
});
</script>

<template>
    <div class="px-5 py-16 sm:px-8 sm:py-24">
        <SeoHead
            title="Page not found"
            description="That scrap is missing from the Snitch contact sheet."
            path="/404"
        />

        <div class="snitch-scrap relative mx-auto max-w-xl overflow-hidden p-8 pt-10 sm:p-12">
            <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
            <div class="snitch-polaroid mx-auto mb-6 max-w-sm" style="--snitch-tilt: -1deg">
                <div class="snitch-polaroid-frame !aspect-[16/10]">
                    <img
                        src="/images/marketing/empty-404.jpg"
                        alt="Snitch detective mascot looking at an empty polaroid"
                        class="opacity-95"
                    />
                </div>
            </div>
            <p class="snitch-ink-label">404</p>
            <h1 class="snitch-display relative z-10 mt-2 text-3xl text-snitch-ink">
                This frame fell off the board.
            </h1>
            <p class="mt-3 text-snitch-ink/80">
                The page you wanted is not here. Head home or sign in to keep
                tracking competitors.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <Link :href="home()" class="snitch-btn">Back home</Link>
                <Link :href="login()" class="snitch-btn snitch-btn-ghost">
                    Log in
                </Link>
            </div>
        </div>
    </div>
</template>
