import type { Auth } from '@/types/auth';

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

export type SubscriptionSummary = {
    plan: string;
    plan_name: string;
    competitor_limit: number;
    competitors_used: number;
    competitors_remaining: number;
    on_trial: boolean;
    trial_ends_at: string | null;
    subscribed: boolean;
    can_upgrade: boolean;
} | null;

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            subscription: SubscriptionSummary;
            sidebarOpen: boolean;
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
