<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Bot, Check } from '@lucide/vue';
import { computed } from 'vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { formatPenceAsGbp } from '@/lib/money';
import {
    SPEND_VENDORS,
    VENDOR_ACCENT_BORDER,
    vendorIconSrc,
    vendorLabel,
} from '@/lib/vendors';
import { agents, login } from '@/routes';
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
    <div>
        <div class="px-5 py-14 sm:px-8 sm:py-20">
            <div class="mx-auto max-w-6xl">
                <h1 class="snitch-display text-4xl text-snitch-ink sm:text-5xl">
                    Platform + usage
                </h1>
                <p class="mt-4 max-w-2xl text-snitch-ink/80">
                    A simple monthly platform fee, then prepaid credits for Apify syncs, NanoGPT analysis, and
                    Firecrawl discovery. No snitch seat caps - you pay for the work you run.
                </p>

                <div class="snitch-contact-reveal mt-12 grid gap-6 md:grid-cols-2">
                    <section class="snitch-scrap relative space-y-4 p-6 pt-8">
                        <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                        <p class="snitch-ink-label">Platform</p>
                        <p class="snitch-display text-4xl text-snitch-ink">
                            {{ formatCatalog(platform.fee_pence) }}<span class="text-lg">/mo</span>
                        </p>
                        <ul class="relative z-10 space-y-2 text-sm text-snitch-ink/80">
                            <li class="flex gap-2">
                                <Check class="mt-0.5 size-4 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                                Unlimited tracked accounts
                            </li>
                            <li class="flex gap-2">
                                <Check class="mt-0.5 size-4 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                                MCP + web app access
                            </li>
                            <li class="flex gap-2">
                                <Check class="mt-0.5 size-4 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                                {{ formatCatalog(platform.bonus_pence) }} usage credits every billing period
                            </li>
                            <li class="flex gap-2">
                                <Check class="mt-0.5 size-4 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                                Feed, Explore, Winners
                            </li>
                        </ul>
                    </section>

                    <section class="snitch-scrap relative space-y-4 p-6 pt-8">
                        <span class="snitch-tape right-4 -top-2" aria-hidden="true" />
                        <p class="snitch-ink-label">Usage credits</p>
                        <p class="snitch-display text-4xl text-snitch-ink">
                            Pay as you go
                        </p>
                        <ul class="relative z-10 space-y-2 text-sm text-snitch-ink/80">
                            <li class="flex gap-2">
                                <Check class="mt-0.5 size-4 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                                Top up £10 / £25 / £50 / £100 when you need more
                            </li>
                            <li class="flex gap-2">
                                <Check class="mt-0.5 size-4 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                                £5 once when you claim your account
                            </li>
                            <li class="flex gap-2">
                                <Check class="mt-0.5 size-4 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                                Usage split by Apify, NanoGPT, and Firecrawl
                            </li>
                        </ul>
                    </section>
                </div>

                <section class="snitch-scrap relative mt-12 space-y-4 p-6 pt-8" data-test="tool-averages">
                    <span class="snitch-tape left-8 -top-2" aria-hidden="true" />
                    <h2 class="snitch-display text-2xl text-snitch-ink sm:text-3xl">
                        Live tool averages
                    </h2>
                    <p class="max-w-2xl text-sm text-snitch-ink/75">
                        Mean charge per run across every Snitch ledger entry, same vendors as Billing.
                        Updated from live usage - shown to four decimal places.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                        <div
                            v-for="row in averagesByVendor"
                            :key="row.vendor"
                            class="border border-snitch-ink/10 border-l-4 bg-snitch-paper/60 p-3"
                            :class="VENDOR_ACCENT_BORDER[row.vendor]"
                            :data-test="`tool-average-${row.vendor}`"
                        >
                            <p class="snitch-ink-label inline-flex items-center gap-1.5">
                                <img
                                    :src="vendorIconSrc(row.vendor)"
                                    alt=""
                                    class="snitch-platform-logo size-3.5 shrink-0 object-contain"
                                    width="14"
                                    height="14"
                                >
                                {{ vendorLabel(row.vendor) }}
                            </p>
                            <p class="snitch-display text-2xl tabular-nums text-snitch-ink">
                                {{ formatAverage(row.avg_pence) }}
                            </p>
                            <p class="text-xs text-snitch-ink/60">
                                {{
                                    row.entries > 0
                                        ? `avg / run · ${row.entries.toLocaleString('en-GB')} runs`
                                        : 'No charges yet'
                                }}
                            </p>
                        </div>
                    </div>
                    <p v-if="!hasLiveCharges" class="text-xs text-snitch-ink/55">
                        Averages fill in as the platform records Apify, NanoGPT, Firecrawl, TikHub, and Snitch charges.
                    </p>
                </section>

                <div class="mt-12 flex flex-wrap gap-3">
                    <Link :href="ctaHref" class="snitch-btn snitch-btn-spot">
                        <span class="relative z-10 inline-flex items-center gap-2">
                            <ArrowRight class="size-3.5 shrink-0" aria-hidden="true" />
                            {{ ctaLabel }}
                        </span>
                    </Link>
                    <Link :href="agents()" class="snitch-btn">
                        <span class="relative z-10 inline-flex items-center gap-2">
                            <Bot class="size-3.5 shrink-0" aria-hidden="true" />
                            Agents / MCP
                        </span>
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
