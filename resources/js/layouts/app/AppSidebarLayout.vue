<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppHead from '@/components/AppHead.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import BillingPaywall from '@/components/BillingPaywall.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppHead />
        <AppSidebar />
        <AppContent variant="sidebar" class="relative min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="relative min-h-[50vh]">
                <slot />
                <BillingPaywall />
            </div>
        </AppContent>
    </AppShell>
</template>
