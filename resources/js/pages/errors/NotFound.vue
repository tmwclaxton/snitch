<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { House, LogIn } from '@lucide/vue';
import SnitchImage from '@/components/SnitchImage.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { home, login } from '@/routes';

defineOptions({
    layout: PublicLayout,
});
</script>

<template>
    <div class="px-5 py-16 sm:px-8 sm:py-24">
        <div class="snitch-scrap relative mx-auto max-w-xl overflow-hidden p-8 pt-10 sm:p-12">
            <span class="snitch-tape left-6 -top-2" aria-hidden="true" />
            <div class="snitch-polaroid mx-auto mb-6 max-w-sm" style="--snitch-tilt: -1deg">
                <div class="snitch-polaroid-frame !aspect-[16/10]">
                    <SnitchImage
                        src="/images/marketing/empty-404.jpg"
                        alt="Snitch detective mascot looking at an empty polaroid"
                        aspect-ratio="16 / 10"
                        loading="eager"
                        class="block size-full opacity-95"
                        img-class="h-full w-full object-cover"
                        fallback="paper"
                    />
                </div>
            </div>
            <p class="snitch-ink-label">404</p>
            <h1 class="snitch-display relative z-10 mt-2 text-3xl text-snitch-ink">
                Whoops, the page you wanted is not here.
            </h1>
            <p class="mt-3 text-snitch-ink/80">
                Head home or sign in to keep tracking competitors.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <Link :href="home()" class="snitch-btn">
                    <span class="relative z-10 inline-flex items-center gap-2">
                        <House class="size-3.5 shrink-0" aria-hidden="true" />
                        Back home
                    </span>
                </Link>
                <Link :href="login()" class="snitch-btn snitch-btn-ghost">
                    <LogIn class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                    <span class="relative z-10">Log in</span>
                </Link>
            </div>
        </div>
    </div>
</template>
