<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Palette, User } from '@lucide/vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
        icon: User,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: Palette,
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-5xl">
            <header class="mb-6 border-b border-snitch-ink/10 pb-5">
                <p class="snitch-ink-label">Snitch / Account</p>
                <h1 class="snitch-display mt-1.5 text-3xl text-snitch-ink sm:text-4xl">
                    Settings
                </h1>
                <p class="mt-1.5 text-sm text-snitch-ink/65 sm:text-base">
                    Profile and appearance on quieter paper.
                </p>
            </header>

            <div class="flex flex-col gap-8 lg:flex-row lg:gap-10">
                <aside class="w-full lg:w-44 lg:shrink-0">
                    <nav
                        class="snitch-paper-nav"
                        aria-label="Settings"
                    >
                        <Link
                            v-for="item in sidebarNavItems"
                            :key="toUrl(item.href)"
                            :href="item.href"
                            class="snitch-paper-nav-link"
                            :class="
                                isCurrentOrParentUrl(item.href)
                                    ? 'snitch-paper-nav-link-active'
                                    : ''
                            "
                        >
                            <component
                                :is="item.icon"
                                v-if="item.icon"
                                class="size-3.5 shrink-0 opacity-70"
                                aria-hidden="true"
                            />
                            {{ item.title }}
                        </Link>
                    </nav>
                </aside>

                <div class="min-w-0 flex-1 md:max-w-2xl">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
