<script setup lang="ts">
import SeoHead from '@/components/marketing/SeoHead.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({
    layout: PublicLayout,
});
</script>

<template>
    <div>
        <SeoHead
            title="About"
            description="Snitch is a personal competitor social tracker for local brands, creators, and agencies."
            path="/about"
        />

        <div class="px-5 py-14 sm:px-8 sm:py-20">
            <article class="snitch-doc relative mx-auto max-w-3xl px-6 py-10 sm:px-10">
                <h1
                    class="snitch-display relative z-10 text-4xl text-snitch-ink sm:text-5xl"
                >
                    Built for people who ship content, not dashboards.
                </h1>
                <div
                    class="relative z-10 mt-8 space-y-5 text-base leading-relaxed text-snitch-ink/80"
                >
                    <p>
                        Snitch helps you see what competitors post across social
                        platforms, understand why those posts work, and surface
                        winners you can remake - scored by rules you control.
                    </p>
                    <p>
                        It is for local brands, creators, and small agencies who
                        want a clear tear sheet of what is working out there -
                        not another multi-seat team suite.
                    </p>
                    <p>
                        Personal accounts only. Auth stays with WorkOS. No
                        billing in v1.
                    </p>
                </div>
            </article>
        </div>
    </div>
</template>
