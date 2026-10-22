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
    KeyRound,
    Lightbulb,
    Megaphone,
    MessageSquare,
    Settings,
    Shield,
    Store,
    Sun,
    Target,
    TrendingUp,
    Users,
    Vote,
} from '@lucide/vue';
import { computed } from 'vue';
import { edit as brand } from '@/actions/App/Http/Controllers/BrandProfileController';
import { index as brief } from '@/actions/App/Http/Controllers/BriefController';
import { index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import { index as today } from '@/actions/App/Http/Controllers/DailyBriefController';
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
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { dashboard, home } from '@/routes';
import { overview as adminOverview, activity as adminActivity } from '@/routes/admin';
import { index as adminCuratorTokensIndex } from '@/routes/admin/curator-tokens';
import { index as adminFeaturesIndex } from '@/routes/admin/features';
import { index as adminUsersIndex } from '@/routes/admin/users';
import { edit as billing } from '@/routes/billing';
import { edit as editProfile } from '@/routes/profile';
import type { NavItem } from '@/types';

const page = usePage();
const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin));
const adminGroupOpen = computed(
    () => currentUrl.value === '/admin' || currentUrl.value.startsWith('/admin/'),
);

const trackedBy = computed(() => {
    const raw = (page.props as { trackedBy?: { count: number; since?: string | null } | null }).trackedBy;

    return raw ?? null;
});

/*
 * Canvas dashboard is panel-based. Question shortcuts open the dedicated
 * product pages (Today, Growth, Competitors) so old section content stays reachable.
 */
const questionNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Canvas',
            href: dashboard.url(),
            icon: Target,
            exact: true,
        },
        {
            title: 'What should we post?',
            href: today(),
            icon: HelpCircle,
        },
        {
            title: 'How are they performing?',
            href: growth(),
            icon: TrendingUp,
        },
        {
            title: 'Are they running ads?',
            href: competitors(),
            icon: Megaphone,
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
        title: 'Vote on features',
        href: `${dashboard.url()}#vote`,
        icon: Vote,
        section: 'vote',
    });

    return items;
});

const mainNavItems: NavItem[] = [
    {
        title: 'Today',
        href: today(),
        icon: Sun,
    },
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
        exact: true,
    },
    {
        title: 'Monthly report',
        href: monthlyReport(),
        icon: FileText,
        exact: true,
    },
    {
        title: 'Explore',
        href: explore(),
        icon: Compass,
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
        class="border-r border-white/10 bg-black"
    >
        <SidebarHeader
            class="shrink-0 border-b border-snitch-ink/10 px-2"
            :class="adminGroupOpen ? 'py-0' : 'py-2'"
        >
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        :class="adminGroupOpen ? 'h-10' : 'h-12'"
                        as-child
                    >
                        <Link :href="home()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="min-h-0 flex-1 gap-0 overflow-y-auto">
            <NavMain
                :items="questionNavItems"
                label="Dashboard"
                :tight="adminGroupOpen"
            />
            <NavMain
                :items="mainNavItems"
                label="Platform"
                :tight="adminGroupOpen"
            />
            <NavMain
                v-if="adminNavItems.length"
                :items="adminNavItems"
                label="Admin"
                collapsible
                :default-open="adminGroupOpen"
                :tight="adminGroupOpen"
            />
        </SidebarContent>

        <SidebarFooter
            class="shrink-0 border-t border-snitch-ink/10 px-2"
            :class="adminGroupOpen ? 'py-0' : 'py-2'"
        >
            <div
                class="flex items-center gap-3 px-2 text-sm"
                :class="adminGroupOpen ? 'h-7' : 'h-8'"
            >
                <Link
                    v-for="item in accountNavItems"
                    :key="item.title"
                    :href="item.href"
                    class="hover:text-sidebar-foreground"
                    :class="isCurrentOrParentUrl(item.href) ? 'snitch-nav-current font-medium text-[#0e0e10]' : 'text-sidebar-foreground/80'"
                >
                    {{ item.title }}
                </Link>
            </div>
            <div :class="adminGroupOpen ? 'mt-1' : 'mt-2'">
                <NavUser :compact="adminGroupOpen" />
            </div>
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
