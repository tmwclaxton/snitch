import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { Component } from 'vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: Component;
    isActive?: boolean;
};
