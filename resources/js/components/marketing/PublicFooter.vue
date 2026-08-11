<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import SnitchBrand from '@/components/SnitchBrand.vue';
import {
    about,
    agents,
    analytics,
    contact,
    cookies,
    home,
    howItWorks,
    pricing,
    privacy,
    terms,
} from '@/routes';
import { index as blog } from '@/routes/blog';

const year = new Date().getFullYear();

const footerSections = [
    {
        title: 'Product',
        links: [
            { label: 'How it works', href: howItWorks() },
            { label: 'Pricing', href: pricing() },
            { label: 'MCP', href: agents() },
            { label: 'Blog', href: blog() },
            { label: 'Analytics', href: analytics() },
        ],
    },
    {
        title: 'Company',
        links: [
            { label: 'About', href: about() },
            { label: 'Contact', href: contact() },
        ],
    },
    {
        title: 'Legal',
        links: [
            { label: 'Privacy', href: privacy() },
            { label: 'Terms', href: terms() },
            { label: 'Cookies', href: cookies() },
        ],
    },
];
</script>

<template>
    <footer class="relative z-10 mt-auto border-t border-snitch-ink/10">
        <div class="mx-auto max-w-6xl px-5 py-12 sm:px-8 sm:py-14">
            <div
                class="grid gap-10 sm:gap-12 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1.4fr)] lg:items-start lg:gap-16"
            >
                <div class="max-w-sm">
                    <Link
                        :href="home()"
                        class="inline-flex text-snitch-ink"
                        aria-label="Snitch home"
                    >
                        <SnitchBrand size="footer" />
                    </Link>
                    <p
                        class="mt-3 text-sm leading-relaxed text-pretty text-snitch-ink/75"
                    >
                        Track public snitch reels, learn why they work, and
                        remake the&nbsp;winners.
                    </p>
                    <p
                        class="snitch-annotation mt-5 text-lg text-snitch-ink/55"
                        aria-hidden="true"
                    >
                        track · remake · win
                    </p>
                </div>

                <nav
                    class="grid grid-cols-2 gap-8 sm:grid-cols-3 sm:gap-10"
                    aria-label="Footer"
                >
                    <div
                        v-for="section in footerSections"
                        :key="section.title"
                        class="min-w-0"
                    >
                        <p class="snitch-ink-label mb-3">
                            {{ section.title }}
                        </p>
                        <ul class="flex flex-col gap-2.5">
                            <li
                                v-for="link in section.links"
                                :key="link.label"
                            >
                                <Link
                                    :href="link.href"
                                    class="text-sm font-medium text-snitch-ink/80 transition-colors hover:text-snitch-ink"
                                    prefetch
                                >
                                    {{ link.label }}
                                </Link>
                            </li>
                        </ul>
                    </div>
                </nav>
            </div>

            <div
                class="mt-10 flex flex-col gap-2 border-t border-snitch-ink/10 pt-6 sm:mt-12 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
            >
                <p class="text-xs text-snitch-ink/50">
                    © {{ year }} Snitch. All rights reserved.
                </p>
                <p class="text-xs text-snitch-ink/45">
                    Public snitch intel for creators who ship.
                </p>
            </div>
        </div>
    </footer>
</template>
