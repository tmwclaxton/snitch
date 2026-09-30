<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, LogOut, Menu, X } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import SnitchBrand from '@/components/SnitchBrand.vue';
import { dashboard, home, login, logout, pricing } from '@/routes';
import { index as blog } from '@/routes/blog';

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
const mobileOpen = ref(false);

const navLinks = [
    { label: 'Pricing', href: () => pricing(), match: '/pricing' },
    { label: 'Blog', href: () => blog(), match: '/blog' },
] as const;

function isActive(match: string): boolean {
    const path = page.url.split('?')[0] ?? '';

    return path === match || path.startsWith(`${match}/`);
}

function closeMobile(): void {
    mobileOpen.value = false;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        closeMobile();
    }
}

watch(
    () => page.url,
    () => {
        closeMobile();
    },
);

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <header class="sticky top-0 z-40 border-b border-white/10 bg-snitch-caution-ink text-snitch-caution-fog backdrop-blur">
        <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-3">
            <div class="flex min-w-0 items-center gap-8">
                <Link :href="home()" class="flex items-center gap-2 text-snitch-caution-fog" aria-label="Snitch home">
                    <SnitchBrand size="nav" />
                </Link>

                <nav
                    v-if="!minimal"
                    class="hidden items-center gap-6 text-sm font-medium text-snitch-caution-fog/75 md:flex"
                    aria-label="Primary"
                >
                    <Link
                        v-for="link in navLinks"
                        :key="link.label"
                        :href="link.href()"
                        class="border-b-2 pb-0.5 transition-colors hover:text-snitch-caution-fog"
                        :class="
                            isActive(link.match)
                                ? 'border-snitch-caution-yellow text-snitch-caution-fog'
                                : 'border-transparent'
                        "
                        prefetch
                    >
                        {{ link.label }}
                    </Link>
                </nav>
            </div>

            <div class="flex items-center gap-2">
                <template v-if="minimal">
                    <Link
                        v-if="isAuthenticated"
                        :href="logout()"
                        method="post"
                        as="button"
                        class="px-3 py-2 text-sm"
                        data-test="logout-button"
                    >
                        <LogOut class="size-3.5" aria-hidden="true" />
                        Log out
                    </Link>
                </template>
                <template v-else>
                    <Link
                        v-if="isAuthenticated"
                        :href="dashboard()"
                        class="hidden items-center gap-2 px-3 py-2 text-sm md:inline-flex"
                    >
                        <LayoutGrid class="size-3.5" aria-hidden="true" />
                        Dashboard
                    </Link>
                    <Link
                        v-else
                        :href="login()"
                        class="hidden bg-snitch-caution-yellow px-3 py-2 text-sm font-medium text-snitch-caution-ink md:inline-flex"
                    >
                        Log in
                    </Link>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center p-2 text-snitch-caution-fog md:hidden"
                        :aria-expanded="mobileOpen"
                        aria-controls="public-mobile-nav"
                        :aria-label="mobileOpen ? 'Close menu' : 'Open menu'"
                        @click="mobileOpen = !mobileOpen"
                    >
                        <X v-if="mobileOpen" class="size-5" aria-hidden="true" />
                        <Menu v-else class="size-5" aria-hidden="true" />
                    </button>
                </template>
            </div>
        </div>

        <div
            v-if="!minimal && mobileOpen"
            id="public-mobile-nav"
            class="border-t border-white/10 bg-snitch-caution-ink md:hidden"
        >
            <nav class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-3" aria-label="Mobile">
                <Link
                    v-for="link in navLinks"
                    :key="link.label"
                    :href="link.href()"
                    class="border-l-2 px-3 py-2.5 text-sm font-medium transition-colors"
                    :class="
                        isActive(link.match)
                            ? 'border-snitch-caution-yellow text-snitch-caution-fog'
                            : 'border-transparent text-snitch-caution-fog/75 hover:text-snitch-caution-fog'
                    "
                    prefetch
                    @click="closeMobile"
                >
                    {{ link.label }}
                </Link>

                <div class="mt-2 border-t border-white/10 pt-3">
                    <Link
                        v-if="isAuthenticated"
                        :href="dashboard()"
                        class="inline-flex w-full items-center gap-2 px-3 py-2.5 text-sm font-medium text-snitch-caution-fog"
                        @click="closeMobile"
                    >
                        <LayoutGrid class="size-3.5" aria-hidden="true" />
                        Dashboard
                    </Link>
                    <Link
                        v-else
                        :href="login()"
                        class="inline-flex w-full items-center justify-center bg-snitch-caution-yellow px-3 py-2.5 text-sm font-medium text-snitch-caution-ink"
                        @click="closeMobile"
                    >
                        Log in
                    </Link>
                </div>
            </nav>
        </div>
    </header>
</template>
