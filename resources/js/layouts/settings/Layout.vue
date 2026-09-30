<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Bot, Palette, User } from '@lucide/vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { show as editMcp } from '@/routes/settings/mcp';
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
            <h1 class="mt-1.5 font-display text-3xl font-semibold tracking-tight sm:text-4xl">
                Settings
            </h1>
            <p class="mt-1.5 text-sm text-snitch-ink/60 sm:text-base">
                Profile, appearance, and MCP.
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
                                ? 'border-snitch-spot bg-snitch-spot/40 font-medium text-snitch-ink'
                                : 'border-transparent text-snitch-ink/60 hover:text-snitch-ink'
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

            <div
                class="min-w-0 flex-1"
                :class="isCurrentOrParentUrl(editMcp()) ? 'max-w-5xl' : 'md:max-w-2xl'"
            >
                <slot />
            </div>
        </div>
    </div>
</template>
