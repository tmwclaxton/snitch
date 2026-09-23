<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, LogOut } from '@lucide/vue';
import { computed } from 'vue';
import SnitchBrand from '@/components/SnitchBrand.vue';
import { dashboard, home, login, logout } from '@/routes';
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
</script>

<template>
    <header class="sticky top-0 z-40 border-b border-neutral-200 bg-white/85 backdrop-blur">
        <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-3">
            <Link :href="home()" class="flex items-center gap-2 text-neutral-950" aria-label="Snitch home">
                <SnitchBrand size="nav" />
            </Link>

            <nav
                v-if="!minimal"
                class="hidden items-center gap-6 text-sm font-medium text-neutral-700 md:flex"
                aria-label="Primary"
            >
                <Link :href="blog()" class="hover:text-neutral-950" prefetch>Blog</Link>
            </nav>

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
                        class="inline-flex items-center gap-2 px-3 py-2 text-sm"
                    >
                        <LayoutGrid class="size-3.5" aria-hidden="true" />
                        Dashboard
                    </Link>
                    <Link
                        v-else
                        :href="login()"
                        class="inline-flex bg-[#F0C400] px-3 py-2 text-sm font-medium text-neutral-950"
                    >
                        Log in
                    </Link>
                </template>
            </div>
        </div>
    </header>
</template>
