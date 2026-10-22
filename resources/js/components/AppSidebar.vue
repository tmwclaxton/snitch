<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Bot,
    CreditCard,
    KeyRound,
    LayoutDashboard,
    Lightbulb,
    MessageSquare,
    Settings,
    Shield,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { dashboard, home } from '@/routes';
import { overview as adminOverview, activity as adminActivity } from '@/routes/admin';
import { index as adminCuratorTokensIndex } from '@/routes/admin/curator-tokens';
import { index as adminFeaturesIndex } from '@/routes/admin/features';
import { index as adminUsersIndex } from '@/routes/admin/users';
import { edit as billing } from '@/routes/billing';
import { index as chatIndex } from '@/routes/chat';
import { edit as editProfile } from '@/routes/profile';
import { show as mcpSettings } from '@/routes/settings/mcp';
import type { NavItem } from '@/types';

const page = usePage();
const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin));
const adminGroupOpen = computed(
    () => currentUrl.value === '/admin' || currentUrl.value.startsWith('/admin/'),
);

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard.url(),
        icon: LayoutDashboard,
        exact: true,
    },
    {
        title: 'Chat',
        href: chatIndex(),
        icon: MessageSquare,
    },
    {
        title: 'Competitors',
        href: competitors(),
        icon: Users,
    },
    {
        title: 'Connect your AI',
        href: mcpSettings(),
        icon: Bot,
    },
];

const adminNavItems = computed<NavItem[]>(() => {
    if (! isAdmin.value) {
        return [];
    }

    return [
        {
            title: 'Admin',
            href: adminOverview(),
            icon: Shield,
            exact: true,
        },
        {
            title: 'Activity',
            href: adminActivity(),
            icon: Activity,
        },
        {
            title: 'Users',
            href: adminUsersIndex(),
            icon: Users,
        },
        {
            title: 'Features',
            href: adminFeaturesIndex(),
            icon: Lightbulb,
        },
        {
            title: 'Curator tokens',
            href: adminCuratorTokensIndex(),
            icon: KeyRound,
        },
    ];
});

const accountNavItems: NavItem[] = [
    {
        title: 'Billing',
        href: billing(),
        icon: CreditCard,
    },
    {
        title: 'Settings',
        href: editProfile(),
        icon: Settings,
    },
];
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="sidebar"
        class="border-r border-sidebar-border bg-sidebar"
    >
        <SidebarHeader class="shrink-0 border-b border-sidebar-border px-3 py-3">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        class="h-12"
                        as-child
                    >
                        <Link :href="home()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="min-h-0 flex-1 gap-1 overflow-y-auto">
            <NavMain
                :items="mainNavItems"
                label="App"
            />
            <NavMain
                v-if="adminNavItems.length"
                :items="adminNavItems"
                label="Admin"
                collapsible
                :default-open="adminGroupOpen"
            />
        </SidebarContent>

        <SidebarFooter class="shrink-0 border-t border-sidebar-border px-3 py-3">
            <div class="flex items-center gap-4 px-2 py-2 text-sm">
                <Link
                    v-for="item in accountNavItems"
                    :key="item.title"
                    :href="item.href"
                    class="rounded-sm px-1.5 py-1 hover:text-sidebar-foreground"
                    :class="isCurrentOrParentUrl(item.href) ? 'snitch-nav-current font-medium text-[#0e0e10]' : 'text-sidebar-foreground/80'"
                >
                    {{ item.title }}
                </Link>
            </div>
            <div class="mt-2">
                <NavUser />
            </div>
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
