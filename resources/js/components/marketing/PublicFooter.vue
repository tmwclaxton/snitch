<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import SnitchBrand from '@/components/SnitchBrand.vue';
import {
    about,
    contact,
    cookies,
    home,
    howItWorks,
    privacy,
    terms,
} from '@/routes';

const footerLinks = [
    { label: 'About', href: about() },
    { label: 'How it works', href: howItWorks() },
    { label: 'Contact', href: contact() },
    { label: 'Privacy', href: privacy() },
    { label: 'Terms', href: terms() },
    { label: 'Cookies', href: cookies() },
];
</script>

<template>
    <footer class="relative z-10 mt-auto border-t border-snitch-ink/10">
        <div
            class="mx-auto flex max-w-6xl flex-col gap-6 px-5 py-10 pb-12 sm:px-8 md:flex-row md:items-end md:justify-between"
        >
            <div class="max-w-2xl shrink-0">
                <Link
                    :href="home()"
                    class="inline-flex text-snitch-ink"
                    aria-label="Snitch home"
                >
                    <SnitchBrand size="footer" />
                </Link>
                <p class="mt-2 text-sm leading-relaxed text-pretty text-snitch-ink/80">
                    Track competitor posts, learn why they work, and remake the&nbsp;winners.
                </p>
            </div>

            <nav
                class="flex flex-wrap gap-x-5 gap-y-2 text-sm font-medium text-snitch-ink/80"
                aria-label="Footer"
            >
                <Link
                    v-for="link in footerLinks"
                    :key="link.label"
                    :href="link.href"
                    class="hover:text-snitch-ink"
                    prefetch
                >
                    {{ link.label }}
                </Link>
            </nav>
        </div>
    </footer>
</template>
