<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { ref, watch } from 'vue';
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

const props = withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
        collapsible?: boolean;
        defaultOpen?: boolean;
        tight?: boolean;
    }>(),
    {
        label: 'Platform',
        collapsible: false,
        defaultOpen: false,
        tight: false,
    },
);

const open = ref(!props.collapsible || props.defaultOpen);

watch(
    () => props.defaultOpen,
    (value) => {
        if (props.collapsible) {
            open.value = value;
        }
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

function toggleGroup(): void {
    if (! props.collapsible) {
        return;
    }

    open.value = ! open.value;
}
</script>

<template>
    <SidebarGroup
        class="px-2 pb-0"
        :class="tight ? 'pt-2.5' : 'pt-3 first:pt-2.5'"
    >
        <SidebarGroupLabel
            class="h-3.5 gap-1 px-2 text-sm leading-none"
            :class="[tight ? 'mb-0.5' : 'mb-1', collapsible ? 'cursor-pointer select-none' : '']"
            :as="collapsible ? 'button' : undefined"
            :aria-expanded="collapsible ? open : undefined"
            @click="toggleGroup"
        >
            <span>{{ label }}</span>
            <ChevronDown
                v-if="collapsible"
                class="ml-auto size-3.5"
                :class="open ? '' : '-rotate-90'"
            />
        </SidebarGroupLabel>
        <SidebarMenu
            v-if="!collapsible || open"
            class="gap-0"
        >
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
