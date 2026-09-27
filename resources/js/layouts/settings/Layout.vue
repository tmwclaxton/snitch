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
    <div class="relative min-h-full bg-white px-4 py-6 text-neutral-950 sm:px-8 sm:py-8">
        <header class="mb-6 border-b border-neutral-200 pb-5">
            <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Account</p>
            <h1 class="mt-1.5 text-3xl font-semibold tracking-tight sm:text-4xl">
                Settings
            </h1>
            <p class="mt-1.5 text-sm text-neutral-500 sm:text-base">
                Profile and appearance preferences.
            </p>
        </header>

        <div class="flex flex-col gap-8 lg:flex-row lg:gap-10">
            <aside class="w-full lg:w-44 lg:shrink-0">
                <nav class="flex flex-col gap-1" aria-label="Settings">
                    <Link
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        :href="item.href"
                        class="inline-flex items-center gap-2 border-l-2 px-3 py-2 text-sm transition-colors"
                        :class="
                            isCurrentOrParentUrl(item.href)
                                ? 'border-[#F0C400] font-medium text-neutral-950'
                                : 'border-transparent text-neutral-600 hover:text-neutral-950'
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
</template>
