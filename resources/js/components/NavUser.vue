<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronsUpDown } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';

withDefaults(
    defineProps<{
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);

const page = usePage();
const user = page.props.auth?.user ?? null;
</script>

<template>
    <SidebarMenu v-if="user">
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        :class="compact ? 'h-10' : 'h-12'"
                        data-test="sidebar-menu-button"
                    >
                        <UserInfo :user="user" />
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    side="right"
                    align="end"
                    :side-offset="12"
                    :collision-padding="16"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
