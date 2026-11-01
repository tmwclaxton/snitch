<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Copy, LoaderCircle, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminReferralController from '@/actions/App/Http/Controllers/Admin/AdminReferralController';
import type { SpendGrain } from '@/components/billing/VendorSpendStackedChart.vue';
import StippleBar from '@/components/dashboard/StippleBar.vue';
import WeeklyVolumeChart from '@/components/dashboard/WeeklyVolumeChart.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPenceAsGbp } from '@/lib/money';
import { index as referralsIndex } from '@/routes/admin/referrals';
import { show as referralShow } from '@/routes/admin/referrals';

type Referral = {
    id: number;
    code: string;
    name: string;
    notes: string | null;
    is_active: boolean;
    public_url: string;
    created_at: string | null;
};

type Kpis = {
    clicks: number;
    signups: number;
    subscribed: number;
    lifetime_usage_pence: number;
    lifetime_payments_pence: number;
};

type PenceBucket = {
    week_start: string;
    label: string;
    pence: number;
};

type DualBucket = {
    week_start: string;
    label: string;
    clicks: number;
    signups: number;
};

type ReferredUser = {
    id: number;
    name: string;
    email: string;
    signed_up_at: string | null;
    created_via: string | null;
    plan_status: string;
    balance_pence: number;
    lifetime_usage_pence: number;
    lifetime_payments_pence: number;
    last_activity_at: string | null;
    snitch_count: number;
    subscription_summary: {
        status: string;
        plan_name: string | null;
        started_at: string | null;
        ends_at: string | null;
    };
    recent_ledger: Array<{
        id: number;
        action: string;
        description: string;
        amount_pence: number;
        created_at: string | null;
    }>;
};

type UsersPaginator = {
    data: ReferredUser[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

const props = defineProps<{
    referral: Referral;
    grain: SpendGrain;
    period_count: number;
    days: number;
    from: string;
    to: string;
    kpis: Kpis;
    signupsSeries: Array<{ week_start: string; label: string; count: number }>;
    usageSeries: PenceBucket[];
    paymentsSeries: PenceBucket[];
    clicksVsSignupsSeries: DualBucket[];
    users: UsersPaginator;
    filters: {
        search: string;
        sort: string;
        direction: string;
        expanded_user_id: number | null;
    };
}>();

defineOptions({
    layout: AppLayout,
});

const editForm = useForm({
    name: props.referral.name,
    notes: props.referral.notes ?? '',
    is_active: props.referral.is_active,
});

const searchInput = ref(props.filters.search);
const copied = ref(false);

const grainOptions: Array<{ value: SpendGrain; label: string }> = [
    { value: 'day', label: 'Daily' },
    { value: 'week', label: 'Weekly' },
    { value: 'month', label: 'Monthly' },
];

const periodLabel = computed(() => {
    switch (props.grain) {
        case 'week':
            return `${props.period_count} wks`;
        case 'month':
            return `${props.period_count} mo`;
        default:
            return `${props.days}d`;
    }
});

function detailHref(overrides: Record<string, string | number | undefined> = {}): string {
    return referralShow.url(props.referral.id, {
        query: {
            grain: props.grain !== 'day' ? props.grain : undefined,
            search: props.filters.search || undefined,
            sort: props.filters.sort !== 'created_at' ? props.filters.sort : undefined,
            direction: props.filters.direction !== 'desc' ? props.filters.direction : undefined,
            expand: props.filters.expanded_user_id ?? undefined,
            page: props.users.current_page > 1 ? props.users.current_page : undefined,
            ...overrides,
        },
    });
}

function grainHref(grain: SpendGrain): string {
    return detailHref({ grain: grain === 'day' ? undefined : grain, page: undefined });
}

function applySearch(): void {
    router.get(
        detailHref({ search: searchInput.value.trim() || undefined, page: undefined }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function toggleSort(column: string): void {
    const nextDirection =
        props.filters.sort === column && props.filters.direction === 'desc' ? 'asc' : 'desc';

    router.get(
        detailHref({ sort: column, direction: nextDirection, page: undefined }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function toggleExpand(userId: number): void {
    const next = props.filters.expanded_user_id === userId ? undefined : userId;

    router.get(
        detailHref({ expand: next }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function saveReferral(): void {
    editForm.patch(AdminReferralController.update.url(props.referral.id), {
        preserveScroll: true,
    });
}

async function copyUrl(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.referral.public_url);
        copied.value = true;
        window.setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        copied.value = false;
    }
}

function formatDate(value: string | null): string {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function penceChartMax(points: PenceBucket[]): number {
    return Math.max(0.01, ...points.map((point) => point.pence));
}

function dualChartMax(points: DualBucket[]): number {
    return Math.max(1, ...points.map((point) => Math.max(point.clicks, point.signups)));
}

const usageMax = computed(() => penceChartMax(props.usageSeries));
const paymentsMax = computed(() => penceChartMax(props.paymentsSeries));
const dualMax = computed(() => dualChartMax(props.clicksVsSignupsSeries));

const chartLeftPad = 52;
const chartHeight = 140;
const chartPlotTop = 10;
const chartSlotWidth = computed(() => (props.grain === 'month' ? 44 : props.grain === 'week' ? 40 : 26));
const chartBarWidth = computed(() => (props.grain === 'month' ? 10 : 8));
const chartLabelPad = computed(() => (props.grain === 'month' ? 36 : 28));
const chartPlotWidth = computed(() =>
    Math.max(chartSlotWidth.value, props.usageSeries.length * chartSlotWidth.value),
);
const chartTotalWidth = computed(() => chartLeftPad + chartPlotWidth.value);

function penceBarHeight(pence: number, max: number): number {
    if (pence <= 0) {
        return 0;
    }

    return Math.max(3, (pence / max) * (chartHeight - chartPlotTop));
}

function penceBarY(pence: number, max: number): number {
    return chartHeight - penceBarHeight(pence, max);
}

function penceBarX(index: number): number {
    return chartLeftPad + index * chartSlotWidth.value + (chartSlotWidth.value - chartBarWidth.value) / 2;
}

function dualGroupX(index: number): number {
    const groupWidth = chartBarWidth.value * 2 + 2;

    return chartLeftPad + index * chartSlotWidth.value + (chartSlotWidth.value - groupWidth) / 2;
}

function countBarHeight(count: number, max: number): number {
    if (count <= 0) {
        return 0;
    }

    return Math.max(3, (count / max) * (chartHeight - chartPlotTop));
}

function countBarY(count: number, max: number): number {
    return chartHeight - countBarHeight(count, max);
}
</script>

<template>
    <div class="snitch-doc mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6">
        <Head :title="`Referral · ${referral.name}`" />

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="snitch-ink-label">Referral</p>
                <h1 class="font-display text-3xl text-snitch-ink">{{ referral.name }}</h1>
                <p class="mt-1 font-mono text-sm text-snitch-ink/70">{{ referral.code }}</p>
                <p class="mt-2 max-w-xl text-sm text-snitch-ink/65">
                    {{ from }} → {{ to }}. First-touch attribution only.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="inline-flex items-center gap-1 text-sm text-snitch-ink/70 hover:text-snitch-ink"
                    @click="copyUrl"
                >
                    <Copy class="size-4" />
                    {{ copied ? 'Copied' : 'Copy link' }}
                </button>
                <Link
                    :href="referralsIndex.url()"
                    class="text-sm text-snitch-ink/70 underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink"
                >
                    All referrals
                </Link>
                <div
                    class="snitch-seg flex flex-wrap gap-1"
                    role="group"
                    aria-label="Period grain"
                >
                    <Link
                        v-for="option in grainOptions"
                        :key="option.value"
                        :href="grainHref(option.value)"
                        class="snitch-seg-item px-3 py-1.5 text-sm"
                        :class="grain === option.value ? 'snitch-seg-item-active' : ''"
                        preserve-scroll
                    >
                        {{ option.label }}
                    </Link>
                </div>
            </div>
        </header>

        <section class="snitch-scrap grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
            <div class="space-y-2">
                <p class="snitch-ink-label">Public URL</p>
                <p class="break-all font-mono text-xs text-snitch-ink/75">{{ referral.public_url }}</p>
                <p class="text-xs text-snitch-ink/55">
                    Status:
                    <span :class="referral.is_active ? 'text-snitch-ink' : 'text-snitch-ink/45'">
                        {{ referral.is_active ? 'active' : 'inactive' }}
                    </span>
                </p>
            </div>
            <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveReferral">
                <label class="block text-sm font-medium text-snitch-ink">
                    Partner name
                    <input v-model="editForm.name" class="snitch-field mt-1" required />
                </label>
                <label class="inline-flex items-center gap-2 self-end text-sm text-snitch-ink">
                    <input v-model="editForm.is_active" type="checkbox" class="rounded border-snitch-ink/30" />
                    Active
                </label>
                <label class="block text-sm font-medium text-snitch-ink sm:col-span-2">
                    Notes
                    <textarea v-model="editForm.notes" class="snitch-field mt-1 min-h-20" />
                </label>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        class="snitch-btn inline-flex items-center gap-2 px-4 py-2 text-sm"
                        :disabled="editForm.processing"
                    >
                        <LoaderCircle v-if="editForm.processing" class="size-4 animate-spin" />
                        Save changes
                    </button>
                </div>
            </form>
        </section>

        <section class="snitch-scrap grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div
                v-for="item in [
                    { label: 'Clicks', value: String(kpis.clicks), hint: 'throttled visits' },
                    { label: 'Signups', value: String(kpis.signups), hint: 'attributed users' },
                    { label: 'Subscribed', value: String(kpis.subscribed), hint: 'active / trial' },
                    { label: 'Lifetime usage', value: formatPenceAsGbp(kpis.lifetime_usage_pence), hint: 'ledger spend' },
                    { label: 'Lifetime payments', value: formatPenceAsGbp(kpis.lifetime_payments_pence), hint: 'plan + top-ups' },
                ]"
                :key="item.label"
                class="border border-snitch-ink/10 bg-snitch-paper/80 px-3 py-3"
            >
                <p class="snitch-ink-label">{{ item.label }}</p>
                <p class="mt-1 font-display text-2xl tabular-nums text-snitch-ink">{{ item.value }}</p>
                <p class="mt-0.5 text-xs text-snitch-ink/55">{{ item.hint }}</p>
            </div>
        </section>

        <section class="snitch-scrap space-y-3 p-4">
            <h2 class="font-display text-xl text-snitch-ink">Signups</h2>
            <WeeklyVolumeChart
                :weeks="signupsSeries"
                title="Referred signups"
                :subtitle="`${signupsSeries.reduce((sum, row) => sum + row.count, 0)} · ${periodLabel}`"
                :dense-labels="grain === 'month' || grain === 'week'"
            />
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="snitch-scrap space-y-3 p-4">
                <h2 class="font-display text-xl text-snitch-ink">Usage spend</h2>
                <div class="relative overflow-x-auto">
                    <svg
                        class="mt-1 w-full overflow-visible"
                        :viewBox="`0 0 ${chartTotalWidth} ${chartHeight + chartLabelPad}`"
                        role="img"
                        aria-label="Referred usage spend chart"
                    >
                        <g
                            v-for="(point, index) in usageSeries"
                            :key="`usage-${point.week_start}`"
                        >
                            <StippleBar
                                v-if="point.pence > 0"
                                :x="penceBarX(index)"
                                :y="penceBarY(point.pence, usageMax)"
                                :width="chartBarWidth"
                                :height="penceBarHeight(point.pence, usageMax)"
                                fill-class="fill-snitch-ink/70"
                                :animate="false"
                                :seed="index * 2"
                            />
                        </g>
                    </svg>
                </div>
            </section>

            <section class="snitch-scrap space-y-3 p-4">
                <h2 class="font-display text-xl text-snitch-ink">Payments credited</h2>
                <div class="relative overflow-x-auto">
                    <svg
                        class="mt-1 w-full overflow-visible"
                        :viewBox="`0 0 ${chartTotalWidth} ${chartHeight + chartLabelPad}`"
                        role="img"
                        aria-label="Referred payments chart"
                    >
                        <g
                            v-for="(point, index) in paymentsSeries"
                            :key="`payments-${point.week_start}`"
                        >
                            <StippleBar
                                v-if="point.pence > 0"
                                :x="penceBarX(index)"
                                :y="penceBarY(point.pence, paymentsMax)"
                                :width="chartBarWidth"
                                :height="penceBarHeight(point.pence, paymentsMax)"
                                fill-class="fill-snitch-spot/90"
                                :animate="false"
                                :seed="index * 2 + 1"
                            />
                        </g>
                    </svg>
                </div>
            </section>
        </div>

        <section class="snitch-scrap space-y-3 p-4">
            <h2 class="font-display text-xl text-snitch-ink">Clicks vs signups</h2>
            <ul class="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-snitch-ink/70">
                <li class="inline-flex items-center gap-1.5">
                    <span class="inline-block size-3 border border-snitch-ink/20 bg-snitch-ink/70" />
                    Clicks
                </li>
                <li class="inline-flex items-center gap-1.5">
                    <span class="inline-block size-3 border border-snitch-ink/20 bg-snitch-spot/90" />
                    Signups
                </li>
            </ul>
            <div class="relative overflow-x-auto">
                <svg
                    class="mt-1 w-full overflow-visible"
                    :viewBox="`0 0 ${chartTotalWidth} ${chartHeight + chartLabelPad}`"
                    role="img"
                    aria-label="Clicks versus signups chart"
                >
                    <g
                        v-for="(point, index) in clicksVsSignupsSeries"
                        :key="`dual-${point.week_start}`"
                    >
                        <StippleBar
                            v-if="point.clicks > 0"
                            :x="dualGroupX(index)"
                            :y="countBarY(point.clicks, dualMax)"
                            :width="chartBarWidth"
                            :height="countBarHeight(point.clicks, dualMax)"
                            fill-class="fill-snitch-ink/70"
                            :animate="false"
                            :seed="index * 3"
                        />
                        <StippleBar
                            v-if="point.signups > 0"
                            :x="dualGroupX(index) + chartBarWidth + 2"
                            :y="countBarY(point.signups, dualMax)"
                            :width="chartBarWidth"
                            :height="countBarHeight(point.signups, dualMax)"
                            fill-class="fill-snitch-spot/90"
                            :animate="false"
                            :seed="index * 3 + 1"
                        />
                    </g>
                </svg>
            </div>
        </section>

        <section class="snitch-scrap space-y-4 p-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="font-display text-xl text-snitch-ink">Referred users</h2>
                    <p class="text-xs text-snitch-ink/55">{{ users.total }} total</p>
                </div>
                <form class="flex max-w-md flex-1 items-center gap-2" @submit.prevent="applySearch">
                    <input
                        v-model="searchInput"
                        type="search"
                        class="snitch-field flex-1"
                        placeholder="Search email or name"
                    />
                    <button type="submit" class="snitch-btn inline-flex items-center gap-1 px-3 py-2 text-sm">
                        <Search class="size-4" />
                        Search
                    </button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-left text-sm">
                    <thead>
                        <tr class="snitch-ink-label border-b border-snitch-ink/10">
                            <th class="py-2 pr-3 font-normal">User</th>
                            <th class="py-2 pr-3 font-normal">Signed up</th>
                            <th class="py-2 pr-3 font-normal">Via</th>
                            <th class="py-2 pr-3 font-normal">Plan</th>
                            <th class="py-2 pr-3 font-normal">Balance</th>
                            <th class="py-2 pr-3 font-normal">
                                <button type="button" class="hover:text-snitch-ink" @click="toggleSort('usage_spend')">
                                    Usage
                                </button>
                            </th>
                            <th class="py-2 pr-3 font-normal">
                                <button type="button" class="hover:text-snitch-ink" @click="toggleSort('payments')">
                                    Payments
                                </button>
                            </th>
                            <th class="py-2 pr-3 font-normal">Snitches</th>
                            <th class="py-2 font-normal">Last activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="user in users.data" :key="user.id">
                            <tr class="border-b border-snitch-ink/8 align-top">
                                <td class="py-2 pr-3">
                                    <button
                                        type="button"
                                        class="text-left hover:text-snitch-ink"
                                        @click="toggleExpand(user.id)"
                                    >
                                        <span class="font-medium text-snitch-ink">{{ user.name }}</span>
                                        <span class="block text-xs text-snitch-ink/60">{{ user.email }}</span>
                                    </button>
                                </td>
                                <td class="py-2 pr-3 text-xs">{{ formatDate(user.signed_up_at) }}</td>
                                <td class="py-2 pr-3 text-xs">{{ user.created_via || '-' }}</td>
                                <td class="py-2 pr-3 text-xs">{{ user.plan_status }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ formatPenceAsGbp(user.balance_pence) }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ formatPenceAsGbp(user.lifetime_usage_pence) }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ formatPenceAsGbp(user.lifetime_payments_pence) }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ user.snitch_count }}</td>
                                <td class="py-2 text-xs">{{ formatDate(user.last_activity_at) }}</td>
                            </tr>
                            <tr
                                v-if="filters.expanded_user_id === user.id"
                                :key="`${user.id}-detail`"
                                class="border-b border-snitch-ink/8 bg-snitch-paper/60"
                            >
                                <td colspan="9" class="px-3 py-3">
                                    <div class="grid gap-4 lg:grid-cols-2">
                                        <div>
                                            <p class="snitch-ink-label">Subscription</p>
                                            <p class="mt-1 text-sm text-snitch-ink">
                                                {{ user.subscription_summary.plan_name || 'No plan' }}
                                                · {{ user.subscription_summary.status }}
                                            </p>
                                            <p class="text-xs text-snitch-ink/55">
                                                Started {{ formatDate(user.subscription_summary.started_at) }}
                                                <span v-if="user.subscription_summary.ends_at">
                                                    · Ends {{ formatDate(user.subscription_summary.ends_at) }}
                                                </span>
                                            </p>
                                        </div>
                                        <div>
                                            <p class="snitch-ink-label">Recent ledger</p>
                                            <ul v-if="user.recent_ledger.length" class="mt-1 space-y-1">
                                                <li
                                                    v-for="entry in user.recent_ledger"
                                                    :key="entry.id"
                                                    class="flex justify-between gap-3 text-xs"
                                                >
                                                    <span class="text-snitch-ink/75">{{ entry.description }}</span>
                                                    <span class="tabular-nums text-snitch-ink">
                                                        {{ formatPenceAsGbp(entry.amount_pence) }}
                                                    </span>
                                                </li>
                                            </ul>
                                            <p v-else class="mt-1 text-xs text-snitch-ink/55">No ledger rows yet.</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!users.data.length">
                            <td colspan="9" class="py-4 text-snitch-ink/55">No referred users yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="users.last_page > 1" class="flex flex-wrap gap-2">
                <Link
                    v-for="page in users.last_page"
                    :key="page"
                    :href="detailHref({ page: page === 1 ? undefined : page })"
                    class="px-2 py-1 text-xs tabular-nums"
                    :class="page === users.current_page ? 'bg-snitch-spot/30 text-snitch-ink' : 'text-snitch-ink/60 hover:text-snitch-ink'"
                    preserve-scroll
                >
                    {{ page }}
                </Link>
            </div>
        </section>
    </div>
</template>
