<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { cookies, home, privacy, pricing, terms } from '@/routes';
import { index as blog } from '@/routes/blog';

const year = new Date().getFullYear();
const page = usePage();
const isHome = computed(() => {
    const path = page.url.split('?')[0] ?? '';

    return path === '/' || path === '/beta';
});
</script>

<template>
    <footer class="border-t border-white/10 bg-snitch-caution-ink">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-4 px-4 py-8 text-[11px] text-snitch-caution-fog/60">
            <Link :href="home()" class="hover:text-snitch-caution-fog">Snitch</Link>
            <span aria-hidden="true">·</span>
            <Link :href="pricing()" class="hover:text-snitch-caution-fog">Pricing</Link>
            <span aria-hidden="true">·</span>
            <Link :href="blog()" class="hover:text-snitch-caution-fog">Blog</Link>
            <span aria-hidden="true">·</span>
            <Link :href="terms()" class="hover:text-snitch-caution-fog">Terms</Link>
            <span aria-hidden="true">·</span>
            <Link :href="privacy()" class="hover:text-snitch-caution-fog">Privacy</Link>
            <span aria-hidden="true">·</span>
            <Link :href="cookies()" class="hover:text-snitch-caution-fog">Cookies</Link>
            <span aria-hidden="true">·</span>
            <a href="mailto:hello@snitchsocial.net" class="hover:text-snitch-caution-fog">Contact</a>
        </div>
        <p
            class="text-center text-[11px] text-snitch-caution-fog/45"
            :class="isHome ? 'pb-24' : 'pb-6'"
        >
            © {{ year }} Snitch
        </p>
    </footer>
</template>
