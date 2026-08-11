<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Bot, Check } from '@lucide/vue';
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
                            {{ formatMoney(1900) }}<span class="text-lg">/mo</span>
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
                                {{ formatMoney(3000) }} usage credits every billing period
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
