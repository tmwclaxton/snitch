<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { cookies, home, privacy, terms } from '@/routes';
import { index as blog } from '@/routes/blog';

const year = new Date().getFullYear();
</script>

<template>
    <footer class="border-t border-neutral-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-4 px-4 py-8 text-[11px] text-neutral-500">
            <Link :href="home()" class="hover:text-neutral-950">Snitch</Link>
            <span aria-hidden="true">·</span>
            <Link :href="blog()" class="hover:text-neutral-950">Blog</Link>
            <span aria-hidden="true">·</span>
            <Link :href="terms()" class="hover:text-neutral-950">Terms</Link>
            <span aria-hidden="true">·</span>
            <Link :href="privacy()" class="hover:text-neutral-950">Privacy</Link>
            <span aria-hidden="true">·</span>
            <Link :href="cookies()" class="hover:text-neutral-950">Cookies</Link>
            <span aria-hidden="true">·</span>
            <a href="mailto:snitch377@gmail.com" class="hover:text-neutral-950">Contact</a>
        </div>
        <p class="pb-6 text-center text-[11px] text-neutral-400">© {{ year }} Snitch</p>
    </footer>
</template>
