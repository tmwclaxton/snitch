<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { computed } from 'vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { formatPenceAsGbp } from '@/lib/money';
import {
    SPEND_VENDORS,
    VENDOR_ACCENT_BORDER,
    vendorIconSrc,
    vendorLabel,
} from '@/lib/vendors';
import { login } from '@/routes';
import { edit as billing } from '@/routes/billing';

defineOptions({
    layout: PublicLayout,
});

type ToolAverage = {
    vendor: string;
    avg_pence: number;
    spend_pence: number;
    entries: number;
};

const props = defineProps<{
    toolAverages: ToolAverage[];
    platform: {
        fee_pence: number;
        bonus_pence: number;
    };
}>();

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const ctaHref = computed(() => (isAuthenticated.value ? billing() : login()));
const ctaLabel = computed(() => (isAuthenticated.value ? 'Open billing' : 'Get started'));

const averagesByVendor = computed(() => {
    const map = new Map(props.toolAverages.map((row) => [row.vendor, row]));

    return SPEND_VENDORS.map((vendor) => {
        const row = map.get(vendor);

        return {
            vendor,
            avg_pence: row?.avg_pence ?? 0,
            entries: row?.entries ?? 0,
        } as const;
    });
});

const hasLiveCharges = computed(() => averagesByVendor.value.some((row) => row.entries > 0));

function formatCatalog(pence: number): string {
    return formatPenceAsGbp(pence, { decimals: 2 });
}

function formatAverage(pence: number): string {
    return formatPenceAsGbp(pence, { decimals: 4 });
}
</script>

<template>
    <div class="px-4 py-14 sm:px-8 sm:py-20">
        <div class="mx-auto max-w-6xl">
            <h1 class="text-4xl font-semibold tracking-tight text-neutral-950 sm:text-5xl">
                Platform + usage
            </h1>
            <p class="mt-4 max-w-2xl text-neutral-600">
                A simple monthly platform fee, then prepaid credits for Apify syncs, NanoGPT analysis, and
                Firecrawl discovery. Signups start with a 7-day trial and £5 to spend. No snitch seat
                caps - you pay for the work you run.
            </p>

            <div class="mt-12 grid gap-6 md:grid-cols-2">
                <section class="space-y-4 border border-neutral-200 bg-white p-6 shadow-[0_20px_50px_-15px_rgba(0,0,0,0.10)] sm:p-8">
                    <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Platform</p>
                    <p class="text-4xl font-semibold tracking-tight text-neutral-950">
                        {{ formatCatalog(platform.fee_pence) }}<span class="text-lg font-medium text-neutral-500">/mo</span>
                    </p>
                    <ul class="space-y-2 text-sm text-neutral-600">
                        <li class="flex gap-2">
                            <Check class="mt-0.5 size-4 shrink-0 text-neutral-950" aria-hidden="true" />
                            Unlimited tracked accounts
                        </li>
                        <li class="flex gap-2">
                            <Check class="mt-0.5 size-4 shrink-0 text-neutral-950" aria-hidden="true" />
                            Web app: Tracking, Feed, Explore, Winners
                        </li>
                        <li class="flex gap-2">
                            <Check class="mt-0.5 size-4 shrink-0 text-neutral-950" aria-hidden="true" />
                            {{ formatCatalog(platform.bonus_pence) }} usage credits every billing period
                        </li>
                    </ul>
                </section>

                <section class="space-y-4 border border-neutral-200 bg-white p-6 shadow-[0_20px_50px_-15px_rgba(0,0,0,0.10)] sm:p-8">
                    <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Usage credits</p>
                    <p class="text-4xl font-semibold tracking-tight text-neutral-950">
                        Pay as you go
                    </p>
                    <ul class="space-y-2 text-sm text-neutral-600">
                        <li class="flex gap-2">
                            <Check class="mt-0.5 size-4 shrink-0 text-neutral-950" aria-hidden="true" />
                            Top up £10 / £25 / £50 / £100 when you need more
                        </li>
                        <li class="flex gap-2">
                            <Check class="mt-0.5 size-4 shrink-0 text-neutral-950" aria-hidden="true" />
                            £5 usage when you create or claim your account
                        </li>
                        <li class="flex gap-2">
                            <Check class="mt-0.5 size-4 shrink-0 text-neutral-950" aria-hidden="true" />
                            7-day trial when you sign up on the website
                        </li>
                        <li class="flex gap-2">
                            <Check class="mt-0.5 size-4 shrink-0 text-neutral-950" aria-hidden="true" />
                            Usage split by Apify, NanoGPT, and Firecrawl
                        </li>
                    </ul>
                </section>
            </div>

            <section class="mt-12 space-y-4 border border-neutral-200 bg-neutral-50 p-6 sm:p-8" data-test="tool-averages">
                <h2 class="text-2xl font-semibold tracking-tight text-neutral-950 sm:text-3xl">
                    Live tool averages
                </h2>
                <p class="max-w-2xl text-sm text-neutral-600">
                    Mean charge per step across every Snitch ledger entry, same vendors as Billing.
                    Updated from live usage - shown to four decimal places.
                </p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    <div
                        v-for="row in averagesByVendor"
                        :key="row.vendor"
                        class="border border-neutral-200 border-l-4 bg-white p-3"
                        :class="VENDOR_ACCENT_BORDER[row.vendor]"
                        :data-test="`tool-average-${row.vendor}`"
                    >
                        <p class="inline-flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-neutral-500">
                            <img
                                :src="vendorIconSrc(row.vendor)"
                                alt=""
                                class="size-3.5 shrink-0 object-contain"
                                width="14"
                                height="14"
                            >
                            {{ vendorLabel(row.vendor) }}
                        </p>
                        <p class="text-2xl font-semibold tabular-nums tracking-tight text-neutral-950">
                            {{ formatAverage(row.avg_pence) }}
                        </p>
                        <p class="text-xs text-neutral-500">
                            {{
                                row.entries > 0
                                    ? `avg / step · ${row.entries.toLocaleString('en-GB')} steps`
                                    : 'No charges yet'
                            }}
                        </p>
                    </div>
                </div>
                <p v-if="!hasLiveCharges" class="text-xs text-neutral-500">
                    Averages fill in as the platform records Apify, NanoGPT, Firecrawl, TikHub, and Snitch charges.
                </p>
            </section>

            <div class="mt-12 flex flex-wrap gap-3">
                <a
                    href="https://calendly.com/dan-olympuslab/30min"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 bg-[#F0C400] px-5 py-2.5 text-sm font-medium text-neutral-950 hover:opacity-90"
                >
                    <ArrowRight class="size-3.5 shrink-0" aria-hidden="true" />
                    Book a free intro call
                </a>
                <Link
                    :href="ctaHref"
                    class="inline-flex items-center border border-neutral-200 px-5 py-2.5 text-sm font-medium text-neutral-950 hover:border-neutral-400"
                >
                    {{ ctaLabel }}
                </Link>
            </div>
        </div>
    </div>
</template>
