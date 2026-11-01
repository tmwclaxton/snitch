<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import { store } from '@/actions/App/Http/Controllers/OnboardingController';
import BillingController from '@/actions/App/Http/Controllers/Settings/BillingController';
import BrandProfileForm from '@/components/BrandProfileForm.vue';
import type { BrandProfileFormData } from '@/components/BrandProfileForm.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { formatPenceAsGbp } from '@/lib/money';

const props = defineProps<{
    step: 'brand' | 'paywall';
    brand: BrandProfileFormData | null;
    platforms: string[];
    trialDays: number;
    platformFeePence: number;
    subscribed: boolean;
}>();

defineOptions({
    layout: PublicLayout,
});

setLayoutProps({ minimal: true });

const feeLabel = computed(() =>
    formatPenceAsGbp(props.platformFeePence, { decimals: 2 }),
);

function startCheckout(): void {
    router.post(BillingController.checkout.url(), {
        product: 'platform',
        from: 'onboarding',
    });
}
</script>

<template>
    <div
        class="bg-snitch-paper px-5 py-6 text-snitch-ink sm:px-8 sm:py-8"
        data-test="onboarding-page"
    >
        <Head title="Onboarding" />

        <div class="mx-auto max-w-3xl">
            <template v-if="step === 'brand'">
                <p class="text-sm font-medium uppercase tracking-[0.12em] text-snitch-ink/70">Brand</p>
                <h1
                    class="snitch-display text-3xl text-snitch-ink sm:text-4xl"
                    data-test="onboarding-step-brand"
                >
                    Tell Snitch about your brand
                </h1>
                <p class="mt-3 max-w-2xl text-sm text-snitch-ink/75">
                    We use this to suggest competitors and keep tracking relevant to your niche.
                </p>

                <BrandProfileForm
                    class="mt-5"
                    :brand="brand"
                    :platforms="platforms"
                    :submit-url="store.url()"
                    submit-method="post"
                    submit-label="Save and continue"
                />
            </template>

            <template v-else>
                <p class="text-sm font-medium uppercase tracking-[0.12em] text-snitch-ink/70">Trial</p>
                <h1
                    class="snitch-display mt-2 text-3xl text-snitch-ink sm:text-4xl"
                    data-test="onboarding-step-paywall"
                >
                    Start your free trial
                </h1>
                <p class="mt-3 max-w-2xl text-sm text-snitch-ink/75">
                    {{ trialDays }}-day free trial. Card taken at checkout. Then {{ feeLabel }} per month
                    platform fee plus usage credits.
                </p>

                <button
                    type="button"
                    class="snitch-btn mt-6 px-4 py-2 text-sm"
                    data-test="onboarding-start-trial"
                    @click="startCheckout"
                >
                    Start {{ trialDays }}-day trial
                </button>
            </template>
        </div>
    </div>
</template>
