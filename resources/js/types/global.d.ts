import type { Auth } from '@/types/auth';

declare global {
    interface Window {
        dataLayer: unknown[];
        gtag?: (...args: unknown[]) => void;
        __SNITCH_GA_EVENTS__?: Array<{
            name: string;
            params?: Record<string, unknown>;
        }>;
    }
}

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

export type PaywallState = {
    blocked: boolean;
    reason: 'subscribe' | 'credits' | null;
    message: string | null;
    starter_allowance_exhausted: boolean;
    can_top_up: boolean;
};

export type SubscriptionSummary = {
    plan: string;
    plan_name: string;
    competitor_limit: number | null;
    competitors_used: number;
    competitors_remaining: number | null;
    over_quota_competitors?: number;
    influencer_limit?: number | null;
    influencers_used?: number;
    influencers_remaining?: number | null;
    over_quota_influencers?: number;
    on_trial: boolean;
    trial_ends_at: string | null;
    subscribed: boolean;
    billing_interval?: string | null;
    can_upgrade: boolean;
    balance_pence?: number;
    min_run_balance_pence?: number;
    can_run_billable?: boolean;
    platform_fee_pence?: number;
    paywall?: PaywallState;
} | null;

export type SeoProps = {
    title: string;
    description: string;
    image: string;
    canonical: string;
    path: string;
    robots: string;
    locale: string;
    site_name: string;
    twitter_card: string;
    json_ld: Record<string, unknown>[];
    indexable: boolean;
};

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            subscription: SubscriptionSummary;
            sidebarOpen: boolean;
            seo: SeoProps;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
