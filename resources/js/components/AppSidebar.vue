<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, Bot, Clapperboard, Compass, CreditCard, LayoutGrid, Link2, Settings, Shield, Store, Trophy, UserRoundSearch, Users } from '@lucide/vue';
import { computed } from 'vue';
import { show as agents } from '@/actions/App/Http/Controllers/AgentsController';
import { edit as brand } from '@/actions/App/Http/Controllers/BrandProfileController';
import { index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import { index as explore } from '@/actions/App/Http/Controllers/ExploreController';
import { index as feed } from '@/actions/App/Http/Controllers/FeedController';
import { index as influencers } from '@/actions/App/Http/Controllers/InfluencerController';
import { index as winners } from '@/actions/App/Http/Controllers/WinnerController';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { dashboard, home } from '@/routes';
import { overview as adminOverview, activity as adminActivity } from '@/routes/admin';
import { index as adminReferrals } from '@/routes/admin/referrals';
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
        title: 'Brand',
        href: brand(),
        icon: Store,
    },
    {
        title: 'Snitches',
        href: competitors(),
        icon: Users,
    },
    {
        title: 'Feed',
        href: feed(),
        icon: Clapperboard,
    },
    {
        title: 'Winners',
        href: winners(),
        icon: Trophy,
    },
    {
        title: 'Explore',
        href: explore(),
        icon: Compass,
    },
    {
        title: 'Brand Deals',
        href: influencers(),
        icon: UserRoundSearch,
    },
    {
        title: 'MCP',
        href: agents(),
        icon: Bot,
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
            {
                title: 'Referrals',
                href: adminReferrals(),
                icon: Link2,
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

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar" class="border-r border-snitch-ink/10 bg-snitch-paper/80">
        <SidebarHeader>
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

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarGroup class="group-data-[collapsible=icon]:p-0">
                <SidebarGroupContent>
                    <SidebarMenu>
                        <SidebarMenuItem
                            v-for="item in accountNavItems"
                            :key="item.title"
                        >
                            <SidebarMenuButton
                                as-child
                                :is-active="isCurrentOrParentUrl(item.href)"
                                :tooltip="item.title"
                            >
                                <Link :href="item.href" prefetch="hover">
                                    <component :is="item.icon" />
                                    <span>{{ item.title }}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
