<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, CreditCard, LayoutGrid, Settings, Shield, Users } from '@lucide/vue';
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
import { dashboard, home } from '@/routes';
import { overview as adminOverview, activity as adminActivity } from '@/routes/admin';
import { index as adminUsersIndex } from '@/routes/admin/users';
import { edit as appearance } from '@/routes/appearance';
import { edit as billing } from '@/routes/billing';
import type { NavItem } from '@/types';

const page = usePage();
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin));

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Competitors',
        href: competitors(),
        icon: Users,
    },
];

const accountNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];

    if (isAdmin.value) {
        items.push(
            {
                title: 'Admin',
                href: adminOverview(),
                icon: Shield,
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
        );
    }

    items.push(
        {
            title: 'Billing',
            href: billing(),
            icon: CreditCard,
        },
        {
            title: 'Settings',
            href: appearance(),
            icon: Settings,
        },
    );

    return items;
});
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="sidebar"
        class="border-r border-neutral-200 bg-white"
    >
        <SidebarHeader class="border-b border-neutral-200">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="home()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-4 overflow-y-auto">
            <NavMain :items="mainNavItems" label="Platform" />
            <NavMain :items="accountNavItems" label="Account" />
        </SidebarContent>

        <SidebarFooter class="mt-auto shrink-0 border-t border-neutral-200">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
