<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { activateDashboardSection, activeDashboardSection } from '@/composables/useDashboardScrollSpy';
import { scrollToDashboardAnchor } from '@/lib/dashboardAnchors';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    {
        label: 'Platform',
    },
);

const { isCurrentOrParentUrl, isCurrentUrl, currentUrl } = useCurrentUrl();

function itemIsActive(item: NavItem): boolean {
    if (item.section) {
        if (currentUrl.value !== '/dashboard' && ! currentUrl.value.startsWith('/dashboard?')) {
            return false;
        }

        return activeDashboardSection.value === item.section;
    }

    if (item.exact) {
        return isCurrentUrl(item.href);
    }

    return isCurrentOrParentUrl(item.href);
}

function onNavClick(event: MouseEvent, item: NavItem): void {
    if (! item.section) {
        return;
    }

    const onDashboard = currentUrl.value === '/dashboard'
        || currentUrl.value.startsWith('/dashboard?');

    if (! onDashboard) {
        return;
    }

    event.preventDefault();
    activateDashboardSection(item.section);
    window.history.replaceState(null, '', `#${item.section}`);
    void scrollToDashboardAnchor(item.section);
}

function hrefFor(item: NavItem): string {
    return toUrl(item.href);
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel class="h-5 text-sm">{{ label }}</SidebarGroupLabel>
        <SidebarMenu class="gap-0">
            <SidebarMenuItem
                v-for="item in items"
                :key="item.title"
            >
                <SidebarMenuButton
                    as-child
                    size="sm"
                    :is-active="itemIsActive(item)"
                    :class="itemIsActive(item) ? 'snitch-nav-current' : ''"
                    :tooltip="item.title"
                >
                    <Link
                        :href="hrefFor(item)"
                        :class="itemIsActive(item) ? 'snitch-nav-current' : ''"
                        :prefetch="item.section ? false : 'hover'"
                        @click="(event) => onNavClick(event, item)"
                    >
                        <component :is="item.icon" />
                        <span class="min-w-0 flex-1">
                            <span class="snitch-nav-label">{{ item.title }}</span>
                        </span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
