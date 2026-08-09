<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { computed } from 'vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { agents, login } from '@/routes';
import { edit as billing } from '@/routes/billing';

defineOptions({
    layout: PublicLayout,
});

const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const ctaHref = computed(() => (isAuthenticated.value ? billing() : login()));
const ctaLabel = computed(() => (isAuthenticated.value ? 'Open billing' : 'Get started'));

function formatMoney(pence: number): string {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        maximumFractionDigits: pence % 100 === 0 ? 0 : 2,
    }).format(pence / 100);
}
</script>

<template>
    <div class="snitch-doc mx-auto max-w-4xl px-4 py-16">
        <p class="snitch-ink-label">Pricing</p>
        <h1 class="font-display mb-3 text-4xl">Platform + usage</h1>
        <p class="mb-10 max-w-2xl text-[var(--snitch-ink)]/75">
            A simple monthly platform fee, then prepaid credits for Apify syncs, NanoGPT analysis, and Firecrawl
            discovery. No competitor seat caps - you pay for the work you run.
        </p>

        <div class="mb-10 grid gap-4 md:grid-cols-2">
            <section class="snitch-scrap space-y-3 p-6">
                <p class="snitch-ink-label">Platform</p>
                <p class="font-display text-4xl">{{ formatMoney(1900) }}<span class="text-lg">/mo</span></p>
                <ul class="space-y-2 text-sm">
                    <li class="flex gap-2"><Check class="mt-0.5 size-4 shrink-0" /> Unlimited tracked accounts</li>
                    <li class="flex gap-2"><Check class="mt-0.5 size-4 shrink-0" /> MCP + web app access</li>
                    <li class="flex gap-2"><Check class="mt-0.5 size-4 shrink-0" /> {{ formatMoney(3000) }} usage credits every billing period</li>
                    <li class="flex gap-2"><Check class="mt-0.5 size-4 shrink-0" /> Feed, Explore, Winners</li>
                </ul>
            </section>
            <section class="snitch-scrap space-y-3 p-6">
                <p class="snitch-ink-label">Usage credits</p>
                <p class="font-display text-4xl">Pay as you go</p>
                <ul class="space-y-2 text-sm">
                    <li class="flex gap-2"><Check class="mt-0.5 size-4 shrink-0" /> Top up £10 / £25 / £50 / £100 when you need more</li>
                    <li class="flex gap-2"><Check class="mt-0.5 size-4 shrink-0" /> £5 once when you claim your account</li>
                    <li class="flex gap-2"><Check class="mt-0.5 size-4 shrink-0" /> Usage split by Apify, NanoGPT, and Firecrawl</li>
                </ul>
            </section>
        </div>

        <div class="flex flex-wrap gap-3">
            <Link :href="ctaHref" class="snitch-btn inline-flex items-center gap-2">
                {{ ctaLabel }}
                <ArrowRight class="size-4" />
            </Link>
            <Link :href="agents()" class="snitch-btn inline-flex items-center gap-2">
                Agents / MCP
            </Link>
        </div>
    </div>
</template>
