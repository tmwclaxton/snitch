<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Bot, User } from '@lucide/vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editProfile } from '@/routes/profile';
import { show as editMcp } from '@/routes/settings/mcp';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
        icon: User,
    },
    // Appearance is hidden while the logged-in app is pinned to Caution Tape
    // night. Leave this entry (and settings/Appearance.vue) so it can come back.
    // {
    //     title: 'Appearance',
    //     href: editAppearance(),
    //     icon: Palette,
    // },
    {
        title: 'MCP',
        href: editMcp(),
        icon: Bot,
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="relative min-h-full bg-snitch-paper px-4 py-6 text-snitch-ink sm:px-8 sm:py-8">
        <header class="mb-6 border-b border-snitch-ink/10 pb-5">
            <p class="font-mono text-sm font-medium uppercase tracking-wide text-snitch-ink/55">Account</p>
            <h1 class="snitch-hero-display mt-1.5 text-3xl sm:text-4xl">
                Settings
            </h1>
            <p class="mt-1.5 text-sm text-snitch-ink/70 sm:text-base">
                Profile and MCP.
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
                                ? 'border-snitch-spot font-medium text-snitch-ink'
                                : 'border-transparent text-snitch-ink/70 hover:text-snitch-ink'
                        "
                    >
                        <component
                            :is="item.icon"
                            v-if="item.icon"
                            class="size-3.5 shrink-0 opacity-70"
                            aria-hidden="true"
                        />
                        <span>
                            {{ item.title }}
                        </span>
                    </Link>
                </nav>
            </aside>

            <div
                class="min-w-0 flex-1"
                :class="isCurrentOrParentUrl(editMcp()) ? 'max-w-5xl' : 'md:max-w-2xl'"
            >
                <slot />
            </div>
        </div>
    </div>
</template>
