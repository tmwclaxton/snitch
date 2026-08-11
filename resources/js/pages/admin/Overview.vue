<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import VendorSpendStackedChart from '@/components/billing/VendorSpendStackedChart.vue';
import type {
    SpendGrain,
    SpendPoint,
} from '@/components/billing/VendorSpendStackedChart.vue';
import PlatformSplitChart from '@/components/dashboard/PlatformSplitChart.vue';
import StippleBar from '@/components/dashboard/StippleBar.vue';
import WeeklyVolumeChart from '@/components/dashboard/WeeklyVolumeChart.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPenceAsGbp } from '@/lib/money';
import { overview as adminOverview } from '@/routes/admin';

type Kpis = {
    users_total: number;
    users_new: number;
    subscribed: number;
    balance_pence: number;
    period_spend_pence: number;
    all_time_spend_pence: number;
    mcp_calls: number;
    failed_analyses: number;
};

type ProfitPoint = {
    date: string;
    label: string;
    charged_gbp: number;
    cogs_gbp: number;
    margin_gbp: number;
};

type McpToolRow = {
    tool: string;
    count: number;
    ok: number;
    errors: number;
};

const props = defineProps<{
    grain: SpendGrain;
    period_count: number;
    days: number;
    from: string;
    to: string;
    kpis: Kpis;
    usersSeries: Array<{ week_start: string; label: string; count: number }>;
    spendSeries: {
        grain: SpendGrain;
        period_count: number;
        days: number;
        from: string;
        to: string;
        points: SpendPoint[];
    };
    profit: {
        charged_gbp: number;
        cogs_gbp: number;
        margin_gbp: number;
        margin_pct: number | null;
        points: ProfitPoint[];
    };
    platforms: Array<{ platform: string; count: number }>;
    analysisStatus: Array<{ status: string; count: number }>;
    failedAnalyses: Array<{
        id: number;
        post_id: number | null;
        platform: string | null;
        error_message: string | null;
        analyzed_at: string | null;
        created_at: string | null;
    }>;
    mcpTools: {
        total: number;
        ok: number;
        errors: number;
        tools: McpToolRow[];
    };
    topActions: Array<{ action: string; count: number; spend_pence: number }>;
    syncFailures: Array<{
        id: number;
        handle: string;
        platform: string;
        last_sync_error: string | null;
        last_synced_at: string | null;
    }>;
}>();

defineOptions({
    layout: AppLayout,
});

const grainOptions: Array<{ value: SpendGrain; label: string }> = [
    { value: 'day', label: 'Daily' },
    { value: 'week', label: 'Weekly' },
    { value: 'month', label: 'Monthly' },
];

function grainHref(grain: SpendGrain): string {
    return adminOverview.url({
        query: grain === 'day' ? {} : { grain },
    });
}

const profitMax = computed(() =>
    Math.max(
        0.0001,
        ...props.profit.points.map((p) =>
            Math.max(p.charged_gbp, p.cogs_gbp),
        ),
    ),
);

const profitLeftPad = 52;
const profitChartHeight = 160;
const profitPlotTop = 10;
const profitBarGap = 6;
const profitPairGap = 2;
const profitPlotWidth = computed(() =>
    Math.max(
        props.grain === 'day' ? 360 : 300,
        props.profit.points.length * (props.grain === 'day' ? 28 : 40),
    ),
);
const profitChartWidth = computed(() => profitLeftPad + profitPlotWidth.value);
const profitGroupWidth = computed(() => {
    const n = Math.max(props.profit.points.length, 1);

    return (profitPlotWidth.value - profitBarGap * (n - 1)) / n;
});
const profitBarWidth = computed(() =>
    Math.max(4, (profitGroupWidth.value - profitPairGap) / 2),
);

const profitYTicks = computed(() => {
    const max = profitMax.value;
    const mid = max / 2;
    const values = max <= 0.01 ? [0, max] : [0, mid, max];

    return values.map((value) => ({
        value,
        label: formatGbpAxis(value),
        y: profitPlotTop + (1 - value / max) * (profitChartHeight - profitPlotTop),
    }));
});

const profitXLabels = computed(() => {
    const every =
        props.grain === 'day'
            ? props.days <= 14
                ? 2
                : props.days <= 31
                  ? 5
                  : 7
            : props.grain === 'week'
              ? 2
              : 1;

    return props.profit.points
        .map((point, index) => ({ point, index }))
        .filter(
            ({ index }) =>
                index % every === 0 || index === props.profit.points.length - 1,
        );
});

function profitGroupX(index: number): number {
    return profitLeftPad + index * (profitGroupWidth.value + profitBarGap);
}

function profitBarHeight(gbp: number): number {
    if (gbp <= 0) {
        return 0;
    }

    return Math.max(3, (gbp / profitMax.value) * (profitChartHeight - profitPlotTop));
}

function profitBarY(gbp: number): number {
    return profitChartHeight - profitBarHeight(gbp);
}

const mcpMax = computed(() =>
    Math.max(1, ...props.mcpTools.tools.map((row) => row.count)),
);

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

function formatGbp(value: number): string {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    }).format(value);
}

function formatGbpAxis(value: number): string {
    if (value === 0) {
        return '£0';
    }

    if (value < 0.01) {
        return formatGbp(value);
    }

    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        minimumFractionDigits: value < 1 ? 2 : 0,
        maximumFractionDigits: value < 1 ? 2 : 0,
    }).format(value);
}
</script>

<template>
    <div class="snitch-doc mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-8 sm:px-6">
        <Head title="Admin" />

        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="snitch-ink-label">Internal</p>
                <h1 class="font-display text-3xl text-snitch-ink">Admin overview</h1>
                <p class="mt-1 max-w-xl text-sm text-snitch-ink/65">
                    Platform-wide usage, spend, profit (COGS vs charge), MCP tools, and failed reels.
                    {{ from }} → {{ to }}.
                </p>
            </div>
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
        </header>

        <section class="snitch-scrap grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div
                v-for="item in [
                    { label: 'Users', value: String(kpis.users_total), hint: `+${kpis.users_new} new` },
                    { label: 'Subscribed', value: String(kpis.subscribed), hint: 'active / trial' },
                    { label: 'Balances', value: formatPenceAsGbp(kpis.balance_pence), hint: 'open credit' },
                    { label: 'Period spend', value: formatPenceAsGbp(kpis.period_spend_pence), hint: 'all vendors' },
                    { label: 'All-time spend', value: formatPenceAsGbp(kpis.all_time_spend_pence), hint: 'ledger charges' },
                    { label: 'MCP calls', value: String(kpis.mcp_calls), hint: periodLabel },
                    { label: 'Failed analyses', value: String(kpis.failed_analyses), hint: 'open failures' },
                    {
                        label: 'Margin',
                        value: formatGbp(profit.margin_gbp),
                        hint: profit.margin_pct != null ? `${profit.margin_pct}% of charge` : 'n/a',
                    },
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
            <WeeklyVolumeChart :weeks="usersSeries" />
        </section>

        <section class="snitch-scrap space-y-3 p-4">
            <h2 class="font-display text-xl text-snitch-ink">Global vendor spend</h2>
            <VendorSpendStackedChart
                :points="spendSeries.points"
                :days="spendSeries.days"
                :period-count="spendSeries.period_count"
                :grain="spendSeries.grain"
            />
        </section>

        <section class="snitch-scrap space-y-3 p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="font-display text-xl text-snitch-ink">Profit (charge vs COGS)</h2>
                <p class="text-xs text-snitch-ink/55">
                    Charged {{ formatGbp(profit.charged_gbp) }}
                    · COGS {{ formatGbp(profit.cogs_gbp) }}
                    · Margin {{ formatGbp(profit.margin_gbp) }}
                    <span v-if="profit.margin_pct != null">
                        ({{ profit.margin_pct }}%)
                    </span>
                </p>
            </div>
            <div class="overflow-x-auto">
                <svg
                    class="mt-1 w-full min-w-[20rem] overflow-visible"
                    :viewBox="`0 0 ${profitChartWidth} ${profitChartHeight + 28}`"
                    role="img"
                    aria-label="Charge versus COGS stipple chart with GBP axis"
                >
                    <g v-for="tick in profitYTicks" :key="`profit-y-${tick.value}`">
                        <line
                            :x1="profitLeftPad"
                            :x2="profitChartWidth"
                            :y1="tick.y"
                            :y2="tick.y"
                            class="stroke-snitch-ink/15"
                            stroke-width="1"
                        />
                        <text
                            :x="profitLeftPad - 6"
                            :y="tick.y + 3"
                            text-anchor="end"
                            class="fill-snitch-ink/55"
                            style="font-size: 9px"
                        >
                            {{ tick.label }}
                        </text>
                    </g>
                    <g
                        v-for="(point, index) in profit.points"
                        :key="point.date"
                    >
                        <StippleBar
                            v-if="point.charged_gbp > 0"
                            :x="profitGroupX(index)"
                            :y="profitBarY(point.charged_gbp)"
                            :width="profitBarWidth"
                            :height="profitBarHeight(point.charged_gbp)"
                            fill-class="fill-snitch-ink/70"
                            :seed="index * 3"
                            :title="`${point.label} charged ${formatGbp(point.charged_gbp)}`"
                        />
                        <StippleBar
                            v-if="point.cogs_gbp > 0"
                            :x="profitGroupX(index) + profitBarWidth + profitPairGap"
                            :y="profitBarY(point.cogs_gbp)"
                            :width="profitBarWidth"
                            :height="profitBarHeight(point.cogs_gbp)"
                            fill-class="fill-snitch-spot/90"
                            :seed="index * 3 + 1"
                            :title="`${point.label} COGS ${formatGbp(point.cogs_gbp)}`"
                        />
                    </g>
                    <text
                        v-for="item in profitXLabels"
                        :key="`profit-x-${item.point.date}`"
                        :x="profitGroupX(item.index) + profitGroupWidth / 2"
                        :y="profitChartHeight + 16"
                        text-anchor="middle"
                        class="fill-snitch-ink/45"
                        style="font-size: 9px"
                    >
                        {{ item.point.label }}
                    </text>
                </svg>
            </div>
            <p class="text-xs text-snitch-ink/55">
                Charcoal = charged · mustard = COGS (admin only; never shown on user billing).
            </p>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="snitch-scrap space-y-3 p-4">
                <h2 class="font-display text-xl text-snitch-ink">Platforms</h2>
                <PlatformSplitChart :platforms="platforms" :period-label="'all posts'" />
            </section>

            <section class="snitch-scrap space-y-3 p-4">
                <h2 class="font-display text-xl text-snitch-ink">Analysis status</h2>
                <ul class="space-y-2">
                    <li
                        v-for="row in analysisStatus"
                        :key="row.status"
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span class="snitch-ink-label">{{ row.status }}</span>
                        <span class="tabular-nums text-snitch-ink">{{ row.count }}</span>
                    </li>
                    <li v-if="!analysisStatus.length" class="text-sm text-snitch-ink/55">No analyses yet.</li>
                </ul>
            </section>
        </div>

        <section class="snitch-scrap space-y-3 p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="font-display text-xl text-snitch-ink">MCP tool usage</h2>
                <p class="text-xs text-snitch-ink/55">
                    {{ mcpTools.total }} calls · {{ mcpTools.ok }} ok · {{ mcpTools.errors }} errors
                </p>
            </div>
            <ul v-if="mcpTools.tools.length" class="space-y-2.5">
                <li
                    v-for="(row, index) in mcpTools.tools"
                    :key="row.tool"
                    class="grid grid-cols-[10rem_minmax(0,1fr)_4rem] items-center gap-2 sm:grid-cols-[14rem_minmax(0,1fr)_5rem]"
                >
                    <span class="truncate font-mono text-xs text-snitch-ink/75">{{ row.tool }}</span>
                    <div class="relative h-4 overflow-hidden bg-snitch-ink/5">
                        <svg class="absolute inset-0 h-full w-full" aria-hidden="true">
                            <StippleBar
                                :x="0"
                                :y="0"
                                :width="Math.max(4, (row.count / mcpMax) * 240)"
                                :height="16"
                                grow-from="left"
                                fill-class="fill-snitch-ink/65"
                                :seed="index * 5"
                            />
                        </svg>
                    </div>
                    <span class="text-right text-xs tabular-nums text-snitch-ink/70">
                        {{ row.count }}
                        <span v-if="row.errors" class="text-snitch-ink/45">({{ row.errors }} err)</span>
                    </span>
                </li>
            </ul>
            <p v-else class="text-sm text-snitch-ink/55">
                No MCP tool calls logged in this window yet (logging starts at deploy).
            </p>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="snitch-scrap space-y-3 p-4">
                <h2 class="font-display text-xl text-snitch-ink">Top ledger actions</h2>
                <ul class="space-y-2">
                    <li
                        v-for="row in topActions"
                        :key="row.action"
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span class="font-mono text-xs text-snitch-ink/70">{{ row.action }}</span>
                        <span class="tabular-nums text-snitch-ink/80">
                            {{ row.count }} · {{ formatPenceAsGbp(row.spend_pence) }}
                        </span>
                    </li>
                    <li v-if="!topActions.length" class="text-sm text-snitch-ink/55">No charges in period.</li>
                </ul>
            </section>

            <section class="snitch-scrap space-y-3 p-4">
                <h2 class="font-display text-xl text-snitch-ink">Sync failures</h2>
                <ul class="space-y-2">
                    <li
                        v-for="row in syncFailures"
                        :key="row.id"
                        class="border-b border-snitch-ink/8 pb-2 text-sm last:border-0"
                    >
                        <p class="font-medium text-snitch-ink">
                            {{ row.handle }}
                            <span class="snitch-ink-label ml-2">{{ row.platform }}</span>
                        </p>
                        <p class="mt-0.5 text-xs text-snitch-ink/55">
                            {{ row.last_sync_error || 'Failed sync' }}
                        </p>
                    </li>
                    <li v-if="!syncFailures.length" class="text-sm text-snitch-ink/55">No failed syncs.</li>
                </ul>
            </section>
        </div>

        <section class="snitch-scrap space-y-3 p-4">
            <h2 class="font-display text-xl text-snitch-ink">Failed reels</h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-left text-sm">
                    <thead>
                        <tr class="snitch-ink-label border-b border-snitch-ink/10">
                            <th class="py-2 pr-3 font-normal">Post</th>
                            <th class="py-2 pr-3 font-normal">Platform</th>
                            <th class="py-2 font-normal">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in failedAnalyses"
                            :key="row.id"
                            class="border-b border-snitch-ink/8 align-top"
                        >
                            <td class="py-2 pr-3 tabular-nums">
                                <Link
                                    v-if="row.post_id"
                                    :href="`/feed/${row.post_id}`"
                                    class="underline decoration-snitch-ink/25 underline-offset-2 hover:decoration-snitch-ink"
                                >
                                    #{{ row.post_id }}
                                </Link>
                                <span v-else>-</span>
                            </td>
                            <td class="py-2 pr-3">{{ row.platform || '-' }}</td>
                            <td class="py-2 text-snitch-ink/70">{{ row.error_message || 'Failed' }}</td>
                        </tr>
                        <tr v-if="!failedAnalyses.length">
                            <td colspan="3" class="py-3 text-snitch-ink/55">No failed analyses.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
