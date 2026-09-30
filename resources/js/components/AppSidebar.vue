<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    CalendarDays,
    Clapperboard,
    Compass,
    CreditCard,
    FileText,
    HelpCircle,
    Lightbulb,
    Megaphone,
    MessageSquare,
    Settings,
    Shield,
    Store,
    Target,
    TrendingUp,
    Users,
    Vote,
} from '@lucide/vue';
import { computed } from 'vue';
import { edit as brand } from '@/actions/App/Http/Controllers/BrandProfileController';
import { index as brief } from '@/actions/App/Http/Controllers/BriefController';
import { index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import { index as explore } from '@/actions/App/Http/Controllers/ExploreController';
import { index as feed } from '@/actions/App/Http/Controllers/FeedController';
import { index as growth } from '@/actions/App/Http/Controllers/GrowthController';
import { show as monthlyReport } from '@/actions/App/Http/Controllers/MonthlyReportController';
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
import { index as adminFeaturesIndex } from '@/routes/admin/features';
import { index as adminUsersIndex } from '@/routes/admin/users';
import { edit as appearance } from '@/routes/appearance';
import { edit as billing } from '@/routes/billing';
import type { NavItem } from '@/types';

const page = usePage();
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin));

const trackedBy = computed(() => {
    const raw = (page.props as { trackedBy?: { count: number; since?: string | null } | null }).trackedBy;

    return raw ?? null;
});

/*
 * Dashboard is organised as four questions (+ vote). Hash links jump to
 * sections; Winners lives inside How are they performing?
 * Ad Library content is the Are they running ads? section.
 */
const questionNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'What should we post?',
            href: `${dashboard.url()}#what-to-post`,
            icon: HelpCircle,
            section: 'what-to-post',
        },
        {
            title: 'How are they performing?',
            href: `${dashboard.url()}#performance`,
            icon: Target,
            section: 'performance',
        },
        {
            title: 'Are they running ads?',
            href: `${dashboard.url()}#ads`,
            icon: Megaphone,
            section: 'ads',
        },
    ];

    if (trackedBy.value != null && trackedBy.value.count > 0) {
        items.push({
            title: 'Is anyone tracking you?',
            href: `${dashboard.url()}#tracked-by`,
            icon: MessageSquare,
            section: 'tracked-by',
        });
    }

    items.push({
        title: 'Cast your vote',
        href: `${dashboard.url()}#vote`,
        icon: Vote,
        section: 'vote',
    });

    return items;
});

const mainNavItems: NavItem[] = [
    {
        title: 'Brand',
        href: brand(),
        icon: Store,
    },
    {
        title: 'Competitors',
        href: competitors(),
        icon: Users,
    },
    {
        title: 'Feed',
        href: feed(),
        icon: Clapperboard,
    },
    {
        title: 'This week',
        href: brief(),
        icon: CalendarDays,
    },
    {
        title: 'Growth',
        href: growth(),
        icon: TrendingUp,
    },
    {
        title: 'Monthly report',
        href: monthlyReport(),
        icon: FileText,
    },
    {
        title: 'Explore',
        href: explore(),
        icon: Compass,
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
                title: 'Features',
                href: adminFeaturesIndex(),
                icon: Lightbulb,
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
            <NavMain :items="questionNavItems" label="Dashboard" />
            <NavMain :items="mainNavItems" label="Platform" />
        </SidebarContent>

        <SidebarFooter class="mt-auto shrink-0 gap-0 border-t border-neutral-200 p-0">
            <div class="pt-2">
                <NavMain :items="accountNavItems" label="Account" />
            </div>
            <div class="border-t border-neutral-200 p-2">
                <NavUser />
            </div>
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
