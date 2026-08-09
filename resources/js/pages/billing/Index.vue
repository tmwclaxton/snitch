<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { CreditCard, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import BillingController from '@/actions/App/Http/Controllers/Settings/BillingController';
import VendorSpendStackedChart from '@/components/billing/VendorSpendStackedChart.vue';
import type { SpendPoint } from '@/components/billing/VendorSpendStackedChart.vue';
import { pricing } from '@/routes';
import { edit } from '@/routes/billing';

type VendorUsage = {
    spend_pence: number;
    entries: number;
};

type UsageSummary = {
    balance_pence: number;
    subscribed: boolean;
    platform_fee_pence: number;
    period_spend_pence: number;
    all_time_spend_pence: number;
    vendors: Record<string, VendorUsage>;
    recent: Array<{
        id: number;
        action: string;
        vendor: string;
        amount_pence: number;
        created_at: string | null;
    }>;
};

type SubscriptionSummary = {
    plan: string;
    plan_name: string;
    subscribed: boolean;
    balance_pence: number;
    platform_fee_pence: number;
    competitors_used: number;
    influencers_used: number;
};

type CreditPack = {
    key: string;
    name: string;
    credits_pence: number;
    price_pence: number;
    has_checkout: boolean;
};

defineProps<{
    subscription: SubscriptionSummary;
    usage: UsageSummary;
    spendSeries: {
        days: number;
        from: string;
        to: string;
        points: SpendPoint[];
    };
    creditPacks: CreditPack[];
    platform: { fee_pence: number; has_checkout: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Billing',
                href: edit(),
            },
        ],
    },
});

const page = usePage();

const checkoutStatus = computed(() => {
    if (page.url.includes('checkout=success') || page.url.includes('checkout=credits_success')) {
        return 'success';
    }

    if (page.url.includes('checkout=cancelled')) {
        return 'cancelled';
    }

    return null;
});

function formatMoney(pence: number): string {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        maximumFractionDigits: Math.abs(pence) % 100 === 0 ? 0 : 2,
    }).format(pence / 100);
}

const vendorLabels: Record<string, string> = {
    apify: 'Apify',
    nanogpt: 'NanoGPT',
    firecrawl: 'Firecrawl',
};

const vendorAccent: Record<string, string> = {
    apify: 'border-l-snitch-ink/70',
    nanogpt: 'border-l-snitch-spot',
    firecrawl: 'border-l-snitch-teal',
};
</script>

<template>
    <Head title="Billing" />

    <div class="snitch-doc mx-auto flex max-w-6xl flex-col gap-8 px-5 py-8 sm:px-8">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0 space-y-2">
                <p class="snitch-ink-label">Account</p>
                <h1 class="font-display text-3xl text-snitch-ink sm:text-4xl">Billing</h1>
                <p class="max-w-2xl text-sm text-snitch-ink/70">
                    Platform access plus prepaid usage. Sync, analysis, and discovery draw from your credit
                    balance.
                </p>
            </div>
            <Link
                :href="pricing()"
                class="text-sm underline decoration-snitch-spot underline-offset-4"
            >
                View public pricing
            </Link>
        </header>

        <p
            v-if="checkoutStatus === 'success'"
            class="rounded-sm border border-snitch-ink/15 bg-snitch-spot/20 px-3 py-2 text-sm"
        >
            Checkout completed. Credits and plan status update shortly after Stripe confirms.
        </p>
        <p
            v-else-if="checkoutStatus === 'cancelled'"
            class="rounded-sm border border-snitch-ink/15 px-3 py-2 text-sm"
        >
            Checkout cancelled. No charge was made.
        </p>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
            <section class="snitch-scrap space-y-4 p-5 sm:p-6">
                <h2 class="font-display text-xl text-snitch-ink">Balance</h2>
                <p class="font-display text-4xl text-snitch-ink sm:text-5xl">
                    {{ formatMoney(usage.balance_pence) }}
                </p>
                <p class="text-sm text-snitch-ink/70">
                    Platform plan:
                    <strong>{{ subscription.subscribed ? 'Active' : 'Not subscribed' }}</strong>
                    · {{ formatMoney(platform.fee_pence) }}/mo
                </p>
                <div class="flex flex-wrap gap-3">
                    <Form
                        v-if="!subscription.subscribed && platform.has_checkout"
                        v-bind="BillingController.checkout.form()"
                        class="inline"
                    >
                        <input type="hidden" name="product" value="platform" />
                        <button type="submit" class="snitch-btn" data-test="subscribe-platform">
                            <span class="flex items-center gap-2">
                                <CreditCard class="size-4" />
                                Subscribe
                                <LoaderCircle class="size-4 animate-spin opacity-0 [[data-loading]_&]:opacity-100" />
                            </span>
                        </button>
                    </Form>
                    <Form v-bind="BillingController.portal.form()" class="inline">
                        <button type="submit" class="snitch-btn" data-test="billing-portal">
                            Stripe portal
                        </button>
                    </Form>
                </div>
            </section>

            <section class="snitch-scrap space-y-4 p-5 sm:p-6">
                <h2 class="font-display text-xl text-snitch-ink">Usage this period</h2>
                <p class="text-sm text-snitch-ink/70">
                    Charged {{ formatMoney(usage.period_spend_pence) }} this month ·
                    {{ formatMoney(usage.all_time_spend_pence) }} all time
                </p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div
                        v-for="(label, key) in vendorLabels"
                        :key="key"
                        class="border border-snitch-ink/10 border-l-4 bg-snitch-paper/60 p-3"
                        :class="vendorAccent[key]"
                    >
                        <p class="snitch-ink-label">{{ label }}</p>
                        <p class="font-display text-2xl text-snitch-ink">
                            {{ formatMoney(usage.vendors[key]?.spend_pence ?? 0) }}
                        </p>
                        <p class="text-xs text-snitch-ink/60">
                            {{ usage.vendors[key]?.entries ?? 0 }} runs
                        </p>
                    </div>
                </div>
            </section>
        </div>

        <section class="snitch-scrap p-5 sm:p-6">
            <VendorSpendStackedChart
                :points="spendSeries.points"
                :days="spendSeries.days"
            />
        </section>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <section class="snitch-scrap space-y-4 p-5 sm:p-6">
                <h2 class="font-display text-xl text-snitch-ink">Top up credits</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <Form
                        v-for="pack in creditPacks"
                        :key="pack.key"
                        v-bind="BillingController.checkout.form()"
                        class="border border-snitch-ink/10 p-3"
                    >
                        <input type="hidden" name="product" value="credits" />
                        <input type="hidden" name="pack" :value="pack.key" />
                        <p class="font-display text-lg text-snitch-ink">{{ pack.name }}</p>
                        <p class="mb-3 text-sm text-snitch-ink/70">
                            {{ formatMoney(pack.credits_pence) }} usage balance
                        </p>
                        <button
                            type="submit"
                            class="snitch-btn"
                            :disabled="!pack.has_checkout"
                            :data-test="`topup-${pack.key}`"
                        >
                            Buy {{ formatMoney(pack.price_pence) }}
                        </button>
                    </Form>
                </div>
            </section>

            <section class="snitch-scrap space-y-3 p-5 sm:p-6">
                <h2 class="font-display text-xl text-snitch-ink">Recent charges</h2>
                <ul
                    v-if="usage.recent.length"
                    class="divide-y divide-snitch-ink/10 text-sm"
                >
                    <li
                        v-for="row in usage.recent"
                        :key="row.id"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <span class="min-w-0">
                            <span class="snitch-ink-label mr-2">{{ row.vendor }}</span>
                            <span class="text-snitch-ink/80">{{ row.action }}</span>
                        </span>
                        <span class="shrink-0 tabular-nums text-snitch-ink">
                            {{ formatMoney(row.amount_pence) }}
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-snitch-ink/60">No usage yet.</p>
            </section>
        </div>
    </div>
</template>
