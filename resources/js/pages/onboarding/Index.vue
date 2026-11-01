<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/OnboardingController';
import BillingController from '@/actions/App/Http/Controllers/Settings/BillingController';
import BrandProfileForm from '@/components/BrandProfileForm.vue';
import type { BrandProfileFormData } from '@/components/BrandProfileForm.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { formatPenceAsGbp } from '@/lib/money';
import { agents } from '@/routes';

const props = defineProps<{
    step?: 'brand' | 'paywall';
    brand: BrandProfileFormData | null;
    platforms: string[];
    trialDays?: number;
    platformFeePence?: number;
}>();

defineOptions({
    layout: PublicLayout,
});

setLayoutProps({ minimal: true });

const localStep = ref<'mcp' | 'brand'>('mcp');
const feeLabel = computed(() => formatPenceAsGbp(props.platformFeePence ?? 1900, { decimals: 2 }));

function startCheckout(): void {
    router.post(BillingController.checkout.url(), {
        product: 'platform',
        from: 'onboarding',
    });
}
const mcpUrl = `${typeof window !== 'undefined' ? window.location.origin : ''}/mcp`;
const tokenHint = 'Create a token on the Agents page after signup, or via MCP create_account.';
</script>

<template>
    <div class="px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Onboarding" />

        <div class="mx-auto max-w-3xl">
            <template v-if="step === 'paywall'">
                <p class="snitch-ink-label">Trial</p>
                <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl" data-test="onboarding-step-paywall">
                    Start your free trial
                </h1>
                <p class="mt-3 max-w-2xl text-sm text-snitch-ink/75">
                    {{ trialDays ?? 7 }}-day free trial. Card taken at checkout. Then {{ feeLabel }} per month
                    platform fee plus usage credits.
                </p>
                <button type="button" class="snitch-btn mt-6" data-test="onboarding-start-trial" @click="startCheckout">
                    Start {{ trialDays ?? 7 }}-day trial
                </button>
            </template>

            <template v-else-if="localStep === 'mcp'">
                <p class="snitch-ink-label">Step 1</p>
                <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                    Connect an agent (optional)
                </h1>
                <p class="mt-3 max-w-2xl text-sm text-snitch-ink/75">
                    Snitch works as an MCP data layer for Cursor, Claude, and other agents. Skip if you only want
                    the website for now - you can connect later from the Agents page.
                </p>

                <div class="snitch-scrap mt-6 space-y-3 p-5 text-sm">
                    <p>
                        Endpoint:
                        <code>{{ mcpUrl }}</code>
                    </p>
                    <p>{{ tokenHint }}</p>
                    <pre class="overflow-x-auto bg-snitch-ink/5 p-3 text-xs">{
  "mcpServers": {
    "snitch": {
      "url": "{{ mcpUrl }}",
      "headers": {
        "Authorization": "Bearer YOUR_SNITCH_API_TOKEN"
      }
    }
  }
}</pre>
                    <Link :href="agents()" class="underline">Full Agents docs</Link>
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="button" class="snitch-btn" @click="localStep = 'brand'">Continue</button>
                    <button type="button" class="text-sm underline" @click="localStep = 'brand'">Skip for now</button>
                </div>
            </template>

            <template v-else>
                <p class="snitch-ink-label">Step 2</p>
                <h1 class="snitch-display text-3xl text-snitch-ink sm:text-4xl">
                    Tell Snitch about your brand
                </h1>

                <BrandProfileForm
                    class="mt-5"
                    :brand="brand"
                    :platforms="platforms"
                    :submit-url="store.url()"
                    submit-method="post"
                    submit-label="Save and continue"
                />
            </template>
        </div>
    </div>
</template>
