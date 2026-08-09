<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    BarChart3,
    ChevronLeft,
    ChevronRight,
    Clapperboard,
    Minus,
    Plus,
    Sparkles,
    Trophy,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AnalysisTermChip from '@/components/AnalysisTermChip.vue';
import DailyMetricChart from '@/components/analytics/DailyMetricChart.vue';
import PlatformStippleTrack from '@/components/PlatformStippleTrack.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { analysisDimensionIcon } from '@/lib/analysisTerms';
import { platformIconSrc } from '@/lib/platforms';
import { analytics as analyticsRoute } from '@/routes';

interface MetricSummary {
    label: string;
    total: number;
    period_total: number;
    series: Array<{
        date: string;
        count: number;
    }>;
}

interface TotalsOnlyMetric {
    label: string;
    total: number;
    period_total: number;
}

interface AnalyticsRange {
    month: string;
    days: number;
    from: string;
    to: string;
    label: string;
    prev_month: string | null;
    next_month: string | null;
    can_go_prev: boolean;
    can_go_next: boolean;
    min_days: number;
    max_days: number;
}

interface TermCount {
    slug: string;
    label: string;
    section?: string | null;
    count: number;
}

interface AnalyticsSummary {
    days: number;
    range: AnalyticsRange;
    metrics: {
        posts_synced: MetricSummary;
        analyses_completed: MetricSummary;
        winners_scored: TotalsOnlyMetric;
    };
    platforms: Array<{
        platform: string;
        label: string;
        count: number;
    }>;
    top_terms: {
        hook_type: TermCount[];
        topic: TermCount[];
        visual_craft: TermCount[];
    };
}

const props = defineProps<{
    analytics: AnalyticsSummary;
}>();

defineOptions({
    layout: PublicLayout,
});

const daysInput = ref(String(props.analytics.range.days));

watch(
    () => props.analytics.range.days,
    (days) => {
        daysInput.value = String(days);
    },
);

const periodLabel = computed(() => {
    const { from, to, label } = props.analytics.range;
    const fromLabel = formatDate(from);
    const toLabel = formatDate(to);
    const spanDays = props.analytics.days;

    if (from === to) {
        return `${label} · ${fromLabel}`;
    }

    return `${label} · ${fromLabel} to ${toLabel} (${spanDays} days)`;
});

const canDecreaseDays = computed(
    () => props.analytics.range.days > props.analytics.range.min_days,
);

const canIncreaseDays = computed(
    () => props.analytics.range.days < props.analytics.range.max_days,
);

const platformMax = computed(() =>
    Math.max(1, ...props.analytics.platforms.map((row) => row.count)),
);

const platformPeakIndex = computed(() => {
    let peak = 0;
    let peakCount = -1;

    props.analytics.platforms.forEach((row, index) => {
        if (row.count > peakCount) {
            peakCount = row.count;
            peak = index;
        }
    });

    return peak;
});

const termGroups = computed(() => [
    {
        key: 'hook_type',
        title: 'Hook types',
        icon: analysisDimensionIcon('hook_type'),
        terms: props.analytics.top_terms.hook_type,
    },
    {
        key: 'topic',
        title: 'Topics',
        icon: analysisDimensionIcon('topic'),
        terms: props.analytics.top_terms.topic,
    },
    {
        key: 'visual_craft',
        title: 'Visual craft',
        icon: analysisDimensionIcon('visual_craft'),
        terms: props.analytics.top_terms.visual_craft,
    },
]);

function formatNumber(value: number): string {
    return new Intl.NumberFormat('en-GB').format(value);
}

function formatDate(value: string): string {
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

function clampDays(value: number): number {
    const { min_days: minDays, max_days: maxDays } = props.analytics.range;

    return Math.min(maxDays, Math.max(minDays, Math.round(value)));
}

function visitRange(month: string, days: number): void {
    const nextDays = clampDays(days);

    if (
        month === props.analytics.range.month &&
        nextDays === props.analytics.range.days
    ) {
        daysInput.value = String(nextDays);

        return;
    }

    router.get(
        analyticsRoute.url({
            query: {
                month,
                days: nextDays,
            },
        }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function goToMonth(month: string | null): void {
    if (!month) {
        return;
    }

    visitRange(month, props.analytics.range.days);
}

function adjustDays(delta: number): void {
    visitRange(props.analytics.range.month, props.analytics.range.days + delta);
}

function commitDaysInput(): void {
    const parsed = Number.parseInt(daysInput.value, 10);

    if (Number.isNaN(parsed)) {
        daysInput.value = String(props.analytics.range.days);

        return;
    }

    visitRange(props.analytics.range.month, parsed);
}
</script>

<template>
    <div>
        <div class="px-5 py-14 sm:px-8 sm:py-20">
            <div class="mx-auto max-w-5xl">
                <header class="mb-8 max-w-3xl">
                    <p class="snitch-ink-label">Analytics</p>
                    <h1
                        class="snitch-display mt-3 text-4xl text-snitch-ink sm:text-5xl"
                    >
                        What Snitch is watching, in the aggregate.
                    </h1>
                    <p class="mt-4 text-base leading-relaxed text-snitch-ink/75">
                        Global totals for posts synced, analyses completed, and
                        winners scored. Platform mix and top craft terms for the
                        selected window. No handles, captions, or per-user
                        stats.
                    </p>
                </header>

                <div
                    class="snitch-scrap mb-8 flex flex-col gap-4 p-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:p-5"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="snitch-ink-label mr-1 w-full sm:mr-2 sm:w-auto">
                            Month
                        </p>
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost inline-flex size-9 items-center justify-center p-0 disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="!analytics.range.can_go_prev"
                            aria-label="Previous month"
                            @click="goToMonth(analytics.range.prev_month)"
                        >
                            <ChevronLeft class="size-4" />
                        </button>
                        <div
                            class="min-w-[9.5rem] border border-snitch-ink/20 bg-snitch-paper px-3 py-1.5 text-center text-sm font-semibold text-snitch-ink"
                            aria-live="polite"
                        >
                            {{ analytics.range.label }}
                        </div>
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost inline-flex size-9 items-center justify-center p-0 disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="!analytics.range.can_go_next"
                            aria-label="Next month"
                            @click="goToMonth(analytics.range.next_month)"
                        >
                            <ChevronRight class="size-4" />
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <p class="snitch-ink-label mr-1 w-full sm:mr-2 sm:w-auto">
                            Days
                        </p>
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost inline-flex size-9 items-center justify-center p-0 disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="!canDecreaseDays"
                            aria-label="Decrease by 7 days"
                            @click="adjustDays(-7)"
                        >
                            <Minus class="size-4" />
                        </button>
                        <div class="flex items-center gap-2">
                            <input
                                id="analytics-days"
                                v-model="daysInput"
                                type="number"
                                inputmode="numeric"
                                :min="analytics.range.min_days"
                                :max="analytics.range.max_days"
                                class="w-16 border border-snitch-ink/20 bg-snitch-paper px-2 py-1.5 text-center text-sm font-semibold text-snitch-ink tabular-nums"
                                aria-label="Number of days in the analytics window"
                                @keydown.enter.prevent="commitDaysInput"
                                @blur="commitDaysInput"
                            />
                            <label
                                for="analytics-days"
                                class="text-sm font-semibold text-snitch-ink"
                            >
                                days
                            </label>
                        </div>
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost inline-flex size-9 items-center justify-center p-0 disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="!canIncreaseDays"
                            aria-label="Increase by 7 days"
                            @click="adjustDays(7)"
                        >
                            <Plus class="size-4" />
                        </button>
                    </div>

                    <p class="w-full text-sm text-snitch-ink/65">
                        Showing {{ periodLabel }}
                    </p>
                </div>

                <div class="mb-8 grid gap-4 lg:grid-cols-3">
                    <div class="snitch-scrap p-5">
                        <div
                            class="mb-3 flex items-center gap-2 text-sm text-snitch-ink/65"
                        >
                            <Clapperboard class="size-4" />
                            Posts synced
                        </div>
                        <p
                            class="snitch-display text-3xl tracking-tight text-snitch-ink"
                        >
                            {{
                                formatNumber(
                                    analytics.metrics.posts_synced.total,
                                )
                            }}
                        </p>
                        <p class="mt-1 text-sm text-snitch-ink/65">
                            {{
                                formatNumber(
                                    analytics.metrics.posts_synced
                                        .period_total,
                                )
                            }}
                            in this window
                        </p>
                    </div>

                    <div class="snitch-scrap p-5">
                        <div
                            class="mb-3 flex items-center gap-2 text-sm text-snitch-ink/65"
                        >
                            <Sparkles class="size-4" />
                            Analyses completed
                        </div>
                        <p
                            class="snitch-display text-3xl tracking-tight text-snitch-ink"
                        >
                            {{
                                formatNumber(
                                    analytics.metrics.analyses_completed.total,
                                )
                            }}
                        </p>
                        <p class="mt-1 text-sm text-snitch-ink/65">
                            {{
                                formatNumber(
                                    analytics.metrics.analyses_completed
                                        .period_total,
                                )
                            }}
                            in this window
                        </p>
                    </div>

                    <div class="snitch-scrap p-5">
                        <div
                            class="mb-3 flex items-center gap-2 text-sm text-snitch-ink/65"
                        >
                            <Trophy class="size-4" />
                            Winners scored
                        </div>
                        <p
                            class="snitch-display text-3xl tracking-tight text-snitch-ink"
                        >
                            {{
                                formatNumber(
                                    analytics.metrics.winners_scored.total,
                                )
                            }}
                        </p>
                        <p class="mt-1 text-sm text-snitch-ink/65">
                            {{
                                formatNumber(
                                    analytics.metrics.winners_scored
                                        .period_total,
                                )
                            }}
                            in this window
                        </p>
                    </div>
                </div>

                <div class="space-y-8">
                    <DailyMetricChart
                        title="Posts synced per day"
                        :description="`Daily totals from ${formatDate(analytics.range.from)} to ${formatDate(analytics.range.to)}.`"
                        empty-title="No posts synced yet."
                        empty-description="As competitors sync public reels, daily totals will appear here."
                        :series="analytics.metrics.posts_synced.series"
                        :days="analytics.days"
                        unit-label="posts"
                    />

                    <DailyMetricChart
                        title="Analyses completed per day"
                        :description="`Completed craft analyses from ${formatDate(analytics.range.from)} to ${formatDate(analytics.range.to)}.`"
                        empty-title="No analyses recorded yet."
                        empty-description="When Snitch finishes analysing posts, daily totals will appear here."
                        :series="analytics.metrics.analyses_completed.series"
                        :days="analytics.days"
                        bar-class="fill-snitch-spot hover:fill-snitch-spot/90"
                        unit-label="analyses"
                    />
                </div>

                <section class="snitch-scrap mt-8 p-5 sm:p-6">
                    <p class="snitch-ink-label">Platform mix</p>
                    <p class="mt-1 text-sm text-snitch-ink/65">
                        Posts synced in this window, by platform.
                    </p>

                    <ul
                        v-if="analytics.platforms.length"
                        class="mt-4 space-y-2.5"
                    >
                        <li
                            v-for="(row, index) in analytics.platforms"
                            :key="row.platform"
                            class="grid grid-cols-[7.5rem_minmax(0,1fr)_3rem] items-center gap-2"
                        >
                            <span class="flex min-w-0 items-center gap-1.5 text-sm text-snitch-ink/75">
                                <img
                                    :src="platformIconSrc(row.platform)"
                                    alt=""
                                    class="snitch-platform-logo size-3.5 shrink-0"
                                    width="14"
                                    height="14"
                                >
                                <span class="truncate">{{ row.label }}</span>
                            </span>
                            <PlatformStippleTrack
                                :count="row.count"
                                :max-count="platformMax"
                                :is-peak="index === platformPeakIndex && row.count > 0"
                                :seed="index + 41"
                                :delay-offset="index * 32"
                                :title="`${row.label}: ${formatNumber(row.count)}`"
                            />
                            <span
                                class="text-right text-xs tabular-nums text-snitch-ink/70"
                            >
                                {{ formatNumber(row.count) }}
                            </span>
                        </li>
                    </ul>
                    <p v-else class="mt-4 text-sm text-snitch-ink/55">
                        No platform activity in this window yet.
                    </p>
                </section>

                <section class="snitch-tear-board mt-8 p-5 sm:p-6">
                    <p class="snitch-ink-label">Top craft terms</p>
                    <p class="mt-1 text-sm text-snitch-ink/65">
                        Catalogue labels most often attached to completed
                        analyses in this window.
                    </p>

                    <div class="mt-5 grid gap-6 md:grid-cols-3">
                        <div
                            v-for="group in termGroups"
                            :key="group.key"
                        >
                            <h3 class="flex items-center gap-1.5 text-sm font-semibold text-snitch-ink">
                                <component
                                    :is="group.icon"
                                    class="size-3.5 shrink-0 text-snitch-ink/55"
                                    aria-hidden="true"
                                />
                                {{ group.title }}
                            </h3>
                            <ul
                                v-if="group.terms.length"
                                class="mt-3 flex flex-wrap gap-2"
                            >
                                <li
                                    v-for="term in group.terms"
                                    :key="term.slug"
                                >
                                    <AnalysisTermChip
                                        variant="picker"
                                        :label="term.label"
                                        :dimension="group.key"
                                        :section="term.section"
                                        :slug="term.slug"
                                        :count="term.count"
                                    />
                                </li>
                            </ul>
                            <p v-else class="mt-3 text-sm text-snitch-ink/55">
                                None yet.
                            </p>
                        </div>
                    </div>
                </section>

                <div class="snitch-doc mt-8 p-5 sm:p-6">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-10 shrink-0 items-center justify-center bg-snitch-spot/40 text-snitch-ink"
                        >
                            <BarChart3 class="size-5" />
                        </div>
                        <div
                            class="space-y-2 text-sm leading-relaxed text-snitch-ink/75"
                        >
                            <p>
                                Posts synced count newly stored competitor
                                posts from successful syncs.
                            </p>
                            <p>
                                Analyses completed count finished craft
                                breakdowns (hook, concept, catalogue terms).
                            </p>
                            <p>
                                Winners scored count new winner insights that
                                pass a user's rules - rescored refreshes do not
                                double-count.
                            </p>
                            <p>
                                Totals are aggregated globally. We do not
                                publish per-user stats, handles, or captions on
                                this page.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
