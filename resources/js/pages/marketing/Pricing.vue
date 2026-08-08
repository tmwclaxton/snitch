<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { computed, ref } from 'vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { dashboard, login } from '@/routes';
import { edit as billing } from '@/routes/billing';

defineOptions({
    layout: PublicLayout,
});

type BillingInterval = 'month' | 'year';

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const ctaHref = computed(() => (isAuthenticated.value ? billing() : login()));
const ctaLabel = computed(() =>
    isAuthenticated.value ? 'Manage billing' : 'Start free trial',
);

const interval = ref<BillingInterval>('month');
const yearlyDiscountPercent = 20;

const plans = [
    {
        key: 'free',
        name: 'Free',
        monthlyPence: 0,
        yearlyPence: 0,
        blurb: 'After your 7-day trial ends, keep watching a small set.',
        limit: '3 competitors',
        features: [
            '7-day trial with Basic limits first',
            'Track Instagram, TikTok, YouTube Shorts, Facebook, LinkedIn',
            'Feed, analysis, and winners',
        ],
        highlight: false,
    },
    {
        key: 'basic',
        name: 'Basic',
        monthlyPence: 2000,
        yearlyPence: 19200,
        blurb: 'Enough seats for a focused rival board.',
        limit: '10 competitors',
        features: [
            'Everything in Free',
            '10 tracked competitors',
            'Cancel anytime in the billing portal',
        ],
        highlight: true,
    },
    {
        key: 'pro',
        name: 'Pro',
        monthlyPence: 9900,
        yearlyPence: 95040,
        blurb: 'Room for a wider market map.',
        limit: '50 competitors',
        features: [
            'Everything in Basic',
            '50 tracked competitors',
            'Best for agencies and multi-brand desks',
        ],
        highlight: false,
    },
];

function formatMoney(pence: number): string {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        maximumFractionDigits: pence % 100 === 0 ? 0 : 2,
    }).format(pence / 100);
}

function displayPrice(plan: (typeof plans)[number]): string {
    if (plan.monthlyPence <= 0) {
        return '£0';
    }

    if (interval.value === 'year') {
        return formatMoney(Math.round(plan.yearlyPence / 12));
    }

    return formatMoney(plan.monthlyPence);
}

function pricePeriod(plan: (typeof plans)[number]): string {
    return plan.monthlyPence > 0 ? '/ mo' : '';
}

function yearlyNote(plan: (typeof plans)[number]): string | null {
    if (plan.monthlyPence <= 0 || interval.value !== 'year') {
        return null;
    }

    return `Billed ${formatMoney(plan.yearlyPence)} / yr`;
}
</script>

<template>
    <div>
        <SeoHead
            title="Pricing"
            description="Snitch plans: 7-day free trial, then Free (3 competitors), Basic (£20 / 10), or Pro (£99 / 50). Save 20% with yearly billing."
            path="/pricing"
        />

        <div class="snitch-app-shell relative px-5 py-14 sm:px-8 sm:py-20">
            <div class="snitch-grain" aria-hidden="true" />

            <div class="relative z-10 mx-auto max-w-6xl">
                <p class="snitch-ink-label">Plans</p>
                <h1 class="snitch-display mt-2 text-4xl text-snitch-ink sm:text-5xl">
                    Simple competitor caps.
                </h1>
                <p class="mt-4 max-w-2xl text-snitch-ink/75">
                    Start with a 7-day trial (Basic limits, no card). Then stay on Free,
                    or upgrade when you need a bigger board. Yearly billing saves
                    {{ yearlyDiscountPercent }}%.
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <div class="snitch-seg" role="group" aria-label="Billing interval">
                        <button
                            type="button"
                            class="snitch-seg-item"
                            :class="interval === 'month' ? 'snitch-seg-item-active' : ''"
                            @click="interval = 'month'"
                        >
                            Monthly
                        </button>
                        <button
                            type="button"
                            class="snitch-seg-item"
                            :class="interval === 'year' ? 'snitch-seg-item-active' : ''"
                            @click="interval = 'year'"
                        >
                            Yearly
                            <span class="ml-1 text-xs opacity-80">
                                -{{ yearlyDiscountPercent }}%
                            </span>
                        </button>
                    </div>
                </div>

                <div class="mt-10 grid gap-5 lg:grid-cols-3">
                    <article
                        v-for="plan in plans"
                        :key="plan.key"
                        class="snitch-scrap relative flex flex-col p-6 pt-8"
                        :class="
                            plan.highlight
                                ? 'shadow-[4px_4px_0_0_var(--snitch-spot)]'
                                : ''
                        "
                    >
                        <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                        <p class="snitch-ink-label relative z-10">{{ plan.name }}</p>
                        <p class="snitch-display relative z-10 mt-2 text-3xl text-snitch-ink">
                            {{ displayPrice(plan) }}
                            <span
                                v-if="pricePeriod(plan)"
                                class="text-base font-normal text-snitch-ink/55"
                            >
                                {{ pricePeriod(plan) }}
                            </span>
                        </p>
                        <p
                            v-if="yearlyNote(plan)"
                            class="relative z-10 mt-1 text-xs text-snitch-ink/55"
                        >
                            {{ yearlyNote(plan) }}
                        </p>
                        <p class="relative z-10 mt-2 text-sm font-medium text-snitch-ink">
                            {{ plan.limit }}
                        </p>
                        <p class="relative z-10 mt-2 text-sm text-snitch-ink/70">
                            {{ plan.blurb }}
                        </p>
                        <ul class="relative z-10 mt-5 space-y-2 text-sm text-snitch-ink/80">
                            <li
                                v-for="feature in plan.features"
                                :key="feature"
                                class="flex gap-2"
                            >
                                <Check
                                    class="mt-0.5 size-3.5 shrink-0 text-snitch-ink/55"
                                    aria-hidden="true"
                                />
                                <span>{{ feature }}</span>
                            </li>
                        </ul>
                        <div class="relative z-10 mt-auto pt-6">
                            <Link
                                :href="ctaHref"
                                class="snitch-btn w-full"
                                :class="plan.highlight ? '' : 'snitch-btn-ghost'"
                            >
                                <span class="relative z-10 inline-flex items-center gap-2">
                                    <ArrowRight class="size-3.5 shrink-0" aria-hidden="true" />
                                    {{ ctaLabel }}
                                </span>
                            </Link>
                        </div>
                    </article>
                </div>

                <p class="mt-8 text-sm text-snitch-ink/60">
                    Already in?
                    <Link
                        :href="isAuthenticated ? dashboard() : login()"
                        class="font-medium text-snitch-ink underline decoration-snitch-ink/30 underline-offset-2"
                    >
                        {{ isAuthenticated ? 'Open dashboard' : 'Log in' }}
                    </Link>
                </p>
            </div>
        </div>
    </div>
</template>
