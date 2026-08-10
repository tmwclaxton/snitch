<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FilterX } from '@lucide/vue';
import { computed } from 'vue';
import { charges as billingCharges, index as billingIndex } from '@/actions/App/Http/Controllers/Settings/BillingController';
import PaperSelect from '@/components/PaperSelect.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { vendorIconSrc, vendorLabel } from '@/lib/vendors';

type ChargeRow = {
    id: number;
    action: string;
    vendor: string;
    amount_pence: number;
    balance_after_pence: number;
    created_at: string | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    charges: {
        data: ChargeRow[];
        links: PaginationLink[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: {
        vendor: string | null;
        action: string | null;
        days: number | null;
    };
    vendors: string[];
    actions: string[];
    usage: { balance_pence: number };
}>();

defineOptions({
    layout: AppLayout,
});

const vendorOptions = computed(() => [
    { value: 'all', label: 'All vendors' },
    ...props.vendors.map((vendor) => ({
        value: vendor,
        label: vendorLabel(vendor),
        iconSrc: vendorIconSrc(vendor),
    })),
]);

const actionOptions = computed(() => [
    { value: 'all', label: 'All actions' },
    ...props.actions.map((action) => ({
        value: action,
        label: action,
    })),
]);

const periodOptions = [
    { value: 'all', label: 'All time' },
    { value: '7', label: 'Last 7 days' },
    { value: '30', label: 'Last 30 days' },
    { value: '90', label: 'Last 90 days' },
];

const selectedVendor = computed(() => props.filters.vendor ?? 'all');
const selectedAction = computed(() => props.filters.action ?? 'all');
const selectedPeriod = computed(() =>
    props.filters.days != null ? String(props.filters.days) : 'all',
);

const hasActiveFilters = computed(
    () =>
        props.filters.vendor != null ||
        props.filters.action != null ||
        props.filters.days != null,
);

function formatMoney(pence: number): string {
    const sign = pence > 0 ? '+' : '';

    return (
        sign +
        new Intl.NumberFormat('en-GB', {
            style: 'currency',
            currency: 'GBP',
            maximumFractionDigits: Math.abs(pence) % 100 === 0 ? 0 : 2,
        }).format(pence / 100)
    );
}

function formatWhen(iso: string | null): string {
    if (!iso) {
        return '-';
    }

    return new Date(iso).toLocaleString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function visitFilters(next: {
    vendor: string | null;
    action: string | null;
    days: number | null;
}): void {
    router.get(
        billingCharges.url(),
        {
            vendor: next.vendor,
            action: next.action,
            days: next.days,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function onVendorChange(value: string): void {
    visitFilters({
        vendor: value === 'all' ? null : value,
        action: props.filters.action,
        days: props.filters.days,
    });
}

function onActionChange(value: string): void {
    visitFilters({
        vendor: props.filters.vendor,
        action: value === 'all' ? null : value,
        days: props.filters.days,
    });
}

function onPeriodChange(value: string): void {
    visitFilters({
        vendor: props.filters.vendor,
        action: props.filters.action,
        days: value === 'all' ? null : Number(value),
    });
}

function clearFilters(): void {
    visitFilters({
        vendor: null,
        action: null,
        days: null,
    });
}

function paginationLabel(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/<[^>]+>/g, '')
        .trim();
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-5 py-6 sm:px-8 sm:py-8">
        <Head title="Billing charges" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="relative z-10 mx-auto max-w-6xl">
            <header class="flex flex-wrap items-end justify-between gap-4 border-b border-snitch-ink/10 pb-5">
                <div class="min-w-0">
                    <p class="snitch-ink-label">Account</p>
                    <h1 class="snitch-display mt-1 text-3xl text-snitch-ink sm:text-4xl">
                        Charge breakdown
                    </h1>
                    <p class="mt-1.5 max-w-2xl text-sm text-snitch-ink/65 sm:text-base">
                        Ledger history for usage charges, top-ups, and bonuses. Balance
                        {{ formatMoney(usage.balance_pence) }}.
                    </p>
                </div>
                <Link
                    :href="billingIndex()"
                    class="text-sm underline decoration-snitch-spot underline-offset-4"
                >
                    Back to billing
                </Link>
            </header>

            <div class="snitch-filter-bar mt-6">
                <label class="snitch-filter-field">
                    <span>Vendor</span>
                    <PaperSelect
                        id="billing-filter-vendor"
                        :model-value="selectedVendor"
                        :options="vendorOptions"
                        aria-label="Filter by vendor"
                        @update:model-value="onVendorChange"
                    />
                </label>
                <label class="snitch-filter-field">
                    <span>Action</span>
                    <PaperSelect
                        id="billing-filter-action"
                        :model-value="selectedAction"
                        :options="actionOptions"
                        aria-label="Filter by action"
                        @update:model-value="onActionChange"
                    />
                </label>
                <label class="snitch-filter-field">
                    <span>Period</span>
                    <PaperSelect
                        id="billing-filter-period"
                        :model-value="selectedPeriod"
                        :options="periodOptions"
                        aria-label="Filter by period"
                        @update:model-value="onPeriodChange"
                    />
                </label>
            </div>

            <div
                v-if="hasActiveFilters"
                class="mt-3 flex justify-end"
            >
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-snitch-ink/55 underline decoration-snitch-ink/20 underline-offset-4 transition hover:text-snitch-ink"
                    @click="clearFilters"
                >
                    <FilterX class="size-3.5 shrink-0" aria-hidden="true" />
                    Clear filters
                </button>
            </div>

            <section class="snitch-scrap relative mt-6 space-y-3 p-5 pt-6 sm:p-6">
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="snitch-display text-2xl text-snitch-ink">Ledger</h2>
                    <p
                        v-if="charges.total > 0"
                        class="text-xs uppercase tracking-wide text-snitch-ink/45"
                    >
                        {{ charges.from }}-{{ charges.to }} of {{ charges.total }}
                    </p>
                </div>

                <div
                    v-if="charges.data.length"
                    class="overflow-x-auto"
                >
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <thead>
                            <tr class="border-b border-snitch-ink/15 text-xs uppercase tracking-wide text-snitch-ink/50">
                                <th class="py-2 pr-3 font-medium">When</th>
                                <th class="py-2 pr-3 font-medium">Vendor</th>
                                <th class="py-2 pr-3 font-medium">Action</th>
                                <th class="py-2 pr-3 text-right font-medium">Amount</th>
                                <th class="py-2 text-right font-medium">Balance after</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-snitch-ink/10">
                            <tr
                                v-for="row in charges.data"
                                :key="row.id"
                            >
                                <td class="py-2.5 pr-3 tabular-nums text-snitch-ink/70">
                                    {{ formatWhen(row.created_at) }}
                                </td>
                                <td class="py-2.5 pr-3">
                                    <span class="snitch-ink-label inline-flex items-center gap-1.5">
                                        <img
                                            :src="vendorIconSrc(row.vendor)"
                                            alt=""
                                            class="snitch-platform-logo size-3.5 shrink-0 object-contain"
                                            width="14"
                                            height="14"
                                        >
                                        {{ vendorLabel(row.vendor) }}
                                    </span>
                                </td>
                                <td class="py-2.5 pr-3 text-snitch-ink/80">
                                    {{ row.action }}
                                </td>
                                <td
                                    class="py-2.5 pr-3 text-right tabular-nums text-snitch-ink"
                                    :class="row.amount_pence > 0 ? 'text-snitch-teal' : ''"
                                >
                                    {{ formatMoney(row.amount_pence) }}
                                </td>
                                <td class="py-2.5 text-right tabular-nums text-snitch-ink/70">
                                    {{ formatMoney(row.balance_after_pence) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-else
                    class="text-sm text-snitch-ink/60"
                >
                    {{ hasActiveFilters ? 'No charges match these filters.' : 'No ledger entries yet.' }}
                </p>
            </section>

            <nav
                v-if="charges.links.length > 3"
                class="mt-8 flex flex-wrap justify-center gap-2"
                aria-label="Pagination"
            >
                <template
                    v-for="(link, index) in charges.links"
                    :key="`${link.label}-${index}`"
                >
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-sm"
                        :class="link.active ? 'snitch-btn-spot' : ''"
                        preserve-scroll
                    >
                        {{ paginationLabel(link.label) }}
                    </Link>
                    <span
                        v-else
                        class="px-3 py-1.5 text-sm text-snitch-ink/35"
                    >
                        {{ paginationLabel(link.label) }}
                    </span>
                </template>
            </nav>
        </div>
    </div>
</template>
