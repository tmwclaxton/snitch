<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { CreditCard, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import BillingController from '@/actions/App/Http/Controllers/Settings/BillingController';
import { pricing } from '@/routes';
import { edit } from '@/routes/billing';

type PlanCard = {
    key: string;
    name: string;
    price_pence: number;
    competitor_limit: number;
    has_checkout: boolean;
};

type SubscriptionSummary = {
    plan: string;
    plan_name: string;
    competitor_limit: number;
    competitors_used: number;
    competitors_remaining: number;
    on_trial: boolean;
    trial_ends_at: string | null;
    subscribed: boolean;
    can_upgrade: boolean;
};

const props = defineProps<{
    subscription: SubscriptionSummary;
    plans: PlanCard[];
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
    const raw = page.url.includes('checkout=success')
        ? 'success'
        : page.url.includes('checkout=cancelled')
          ? 'cancelled'
          : null;

    return raw;
});

function formatPrice(pence: number): string {
    if (pence <= 0) {
        return '£0';
    }

    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        maximumFractionDigits: 0,
    }).format(pence / 100);
}

function trialLabel(): string | null {
    if (!props.subscription.on_trial || !props.subscription.trial_ends_at) {
        return null;
    }

    const ends = new Date(props.subscription.trial_ends_at);
    const days = Math.max(
        0,
        Math.ceil((ends.getTime() - Date.now()) / (1000 * 60 * 60 * 24)),
    );

    if (days <= 0) {
        return 'Trial ends today';
    }

    return days === 1 ? '1 day left on trial' : `${days} days left on trial`;
}

function isPaidCurrentPlan(plan: PlanCard): boolean {
    return props.subscription.subscribed && props.subscription.plan === plan.key;
}

function canCheckout(plan: PlanCard): boolean {
    return plan.has_checkout && !isPaidCurrentPlan(plan);
}

function checkoutLabel(plan: PlanCard): string {
    if (plan.key === 'basic') {
        return props.subscription.subscribed ? `Switch to ${plan.name}` : `Start ${plan.name}`;
    }

    if (props.subscription.subscribed || props.subscription.on_trial) {
        return `Upgrade to ${plan.name}`;
    }

    return `Start ${plan.name}`;
}

function cardHighlight(plan: PlanCard): boolean {
    if (props.subscription.subscribed) {
        return props.subscription.plan === plan.key;
    }

    return plan.key === 'basic' && props.subscription.on_trial;
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Billing" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-5xl space-y-8">
            <header>
                <p class="snitch-ink-label">Account</p>
                <h1 class="snitch-display mt-1.5 text-3xl text-snitch-ink sm:text-4xl">
                    Billing
                </h1>
                <p class="mt-1.5 text-sm text-snitch-ink/65 sm:text-base">
                    Plans gate how many competitors you can track. Trial starts free with no card.
                    <Link
                        :href="pricing()"
                        class="ml-1 font-medium underline decoration-snitch-ink/30 underline-offset-2"
                    >
                        See public pricing
                    </Link>
                </p>
            </header>

            <p
                v-if="checkoutStatus === 'success'"
                class="border border-snitch-ink/15 bg-snitch-spot/25 px-3 py-2 text-sm text-snitch-ink"
            >
                Checkout complete. Stripe may take a moment to confirm your subscription.
            </p>
            <p
                v-else-if="checkoutStatus === 'cancelled'"
                class="border border-snitch-ink/15 bg-snitch-ink/[0.04] px-3 py-2 text-sm text-snitch-ink/75"
            >
                Checkout cancelled. Your plan is unchanged.
            </p>

            <div class="snitch-doc relative space-y-8 p-5 sm:p-6">
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />

                <div class="relative z-10">
                    <div
                        class="border border-snitch-ink/12 bg-snitch-ink/[0.03] px-4 py-3"
                    >
                        <p class="snitch-ink-label">Current plan</p>
                        <p class="snitch-display mt-1 text-xl text-snitch-ink">
                            {{ subscription.plan_name }}
                            <span
                                v-if="subscription.on_trial"
                                class="ml-2 align-middle text-sm font-normal text-snitch-ink/60"
                            >
                                (trial)
                            </span>
                        </p>
                        <p class="mt-1 text-sm text-snitch-ink/70">
                            Competitors:
                            {{ subscription.competitors_used }} /
                            {{ subscription.competitor_limit }}
                        </p>
                        <p
                            v-if="trialLabel()"
                            class="mt-1 text-sm text-snitch-ink/65"
                        >
                            {{ trialLabel() }}
                        </p>
                        <p
                            v-if="subscription.on_trial && !subscription.subscribed"
                            class="mt-2 text-sm text-snitch-ink/65"
                        >
                            Trial uses Basic limits. Start Basic or Pro anytime to keep access after day 7.
                        </p>

                        <Form
                            v-if="subscription.subscribed"
                            :action="BillingController.portal.url()"
                            method="post"
                            class="mt-4"
                            #default="{ processing }"
                        >
                            <button
                                type="submit"
                                class="snitch-btn snitch-btn-ghost"
                                :disabled="processing"
                            >
                                <LoaderCircle
                                    v-if="processing"
                                    class="size-4 animate-spin"
                                />
                                <CreditCard v-else class="size-4" />
                                Manage subscription
                            </button>
                        </Form>
                    </div>
                </div>

                <div class="relative z-10 grid gap-4 sm:grid-cols-3">
                    <article
                        v-for="plan in plans"
                        :key="plan.key"
                        class="border border-snitch-ink/12 bg-snitch-paper/80 p-4"
                        :class="
                            cardHighlight(plan)
                                ? 'shadow-[3px_3px_0_0_var(--snitch-spot)]'
                                : ''
                        "
                    >
                        <p class="snitch-ink-label">{{ plan.name }}</p>
                        <p class="snitch-display mt-1 text-2xl text-snitch-ink">
                            {{ formatPrice(plan.price_pence) }}
                            <span
                                v-if="plan.price_pence > 0"
                                class="text-sm font-normal text-snitch-ink/55"
                            >
                                / mo
                            </span>
                        </p>
                        <p class="mt-2 text-sm text-snitch-ink/70">
                            {{ plan.competitor_limit }} competitors
                        </p>

                        <p
                            v-if="isPaidCurrentPlan(plan)"
                            class="mt-4 text-sm font-medium text-snitch-ink"
                        >
                            Your plan
                        </p>
                        <p
                            v-else-if="plan.key === 'free' && subscription.on_trial"
                            class="mt-4 text-sm text-snitch-ink/60"
                        >
                            After trial if you do not subscribe
                        </p>
                        <p
                            v-else-if="plan.key === 'free' && subscription.plan === 'free'"
                            class="mt-4 text-sm font-medium text-snitch-ink"
                        >
                            Your plan
                        </p>
                        <Form
                            v-else-if="canCheckout(plan)"
                            :action="BillingController.checkout.url()"
                            method="post"
                            class="mt-4"
                            #default="{ processing }"
                        >
                            <input type="hidden" name="plan" :value="plan.key" />
                            <button
                                type="submit"
                                class="snitch-btn w-full"
                                :disabled="processing"
                            >
                                <LoaderCircle
                                    v-if="processing"
                                    class="size-4 animate-spin"
                                />
                                {{ checkoutLabel(plan) }}
                            </button>
                        </Form>
                        <p
                            v-else-if="plan.key !== 'free'"
                            class="mt-4 text-xs text-snitch-ink/50"
                        >
                            Price not configured yet.
                        </p>
                    </article>
                </div>
            </div>
        </div>
    </div>
</template>
