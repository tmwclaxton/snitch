<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { edit as billing } from '@/routes/billing';
import type { SubscriptionSummary } from '@/types/global';

const page = usePage();

const subscription = computed(
    () => page.props.subscription as SubscriptionSummary,
);

const paywall = computed(() => subscription.value?.paywall ?? null);

const onBillingPage = computed(() => {
    const url = page.url.split('?')[0] ?? '';

    return url === '/billing' || url.startsWith('/billing/');
});

const blocked = computed(
    () => paywall.value?.blocked === true && !onBillingPage.value,
);

const title = computed(() => {
    if (paywall.value?.reason === 'credits') {
        return 'Usage allowance spent';
    }

    return 'Paid plan required';
});

const description = computed(() => {
    return (
        paywall.value?.message ??
        'Subscribe to a paid plan on the Billing page to continue.'
    );
});
</script>

<template>
    <div
        v-if="blocked"
        class="pointer-events-none absolute inset-0 z-20 bg-snitch-paper/40 backdrop-blur-[2px]"
        aria-hidden="true"
    />

    <Dialog :open="blocked">
        <DialogContent
            class="snitch-scrap border-snitch-ink/20 bg-snitch-paper sm:max-w-md"
            :show-close-button="false"
            @escape-key-down.prevent
            @pointer-down-outside.prevent
            @interact-outside.prevent
        >
            <DialogHeader>
                <DialogTitle class="snitch-display text-2xl text-snitch-ink">
                    {{ title }}
                </DialogTitle>
                <DialogDescription class="text-sm text-snitch-ink/75">
                    {{ description }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="sm:justify-start">
                <Link :href="billing()" class="snitch-btn" data-test="paywall-billing-cta">
                    Go to Billing
                </Link>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
