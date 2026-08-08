<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SnitchBrand from '@/components/SnitchBrand.vue';
import {
    about,
    analytics,
    contact,
    dashboard,
    home,
    howItWorks,
    login,
    logout,
} from '@/routes';

withDefaults(
    defineProps<{
        minimal?: boolean;
    }>(),
    {
        minimal: false,
    },
);

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const overHero = computed(
    () => page.url === '/' || page.url.startsWith('/?'),
);

const links = [
    { label: 'How it works', href: howItWorks() },
    { label: 'Analytics', href: analytics() },
    { label: 'About', href: about() },
    { label: 'Contact', href: contact() },
];
</script>

<template>
    <header
        class="snitch-nav z-30"
        :class="overHero ? 'snitch-nav-hero' : 'relative'"
    >
        <div
            class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-5 py-5 sm:px-8"
        >
            <Link
                :href="home()"
                class="min-w-0 shrink text-snitch-ink"
                aria-label="Snitch home"
            >
                <SnitchBrand size="nav" />
            </Link>

            <nav
                v-if="!minimal"
                class="hidden items-center gap-6 text-sm font-semibold text-snitch-ink md:flex"
                aria-label="Primary"
            >
                <Link
                    v-for="link in links"
                    :key="link.label"
                    :href="link.href"
                    class="transition-colors hover:text-snitch-ink/80"
                    prefetch
                >
                    {{ link.label }}
                </Link>
            </nav>

            <div class="flex items-center gap-2">
                <template v-if="minimal">
                    <Link
                        v-if="isAuthenticated"
                        :href="logout()"
                        method="post"
                        as="button"
                        class="snitch-btn snitch-btn-ghost px-3 py-2 text-sm"
                        data-test="logout-button"
                    >
                        Log out
                    </Link>
                </template>
                <template v-else>
                    <Link
                        v-if="isAuthenticated"
                        :href="dashboard()"
                        class="snitch-btn snitch-btn-ghost px-3 py-2 text-sm"
                    >
                        Dashboard
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="snitch-btn hidden px-3 py-2 text-sm sm:inline-flex"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="login()"
                            class="snitch-btn snitch-btn-spot px-3 py-2 text-sm"
                        >
                            Sign up
                        </Link>
                    </template>
                </template>
            </div>
        </div>
    </header>
</template>
