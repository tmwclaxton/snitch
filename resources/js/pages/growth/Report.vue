<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Download, Link2, Link2Off } from '@lucide/vue';
import { computed } from 'vue';
import GrowthController from '@/actions/App/Http/Controllers/GrowthController';
import MonthlyReportController from '@/actions/App/Http/Controllers/MonthlyReportController';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout,
});

type Kpi = {
    you: number | null;
    you_prev: number | null;
    you_change: number | null;
    peer: number | null;
    peer_change: number | null;
};

type Report = {
    id: number;
    month: string;
    month_label: string;
    kpis: Record<string, Kpi>;
    own_top_posts: Array<{ id: number; caption: string; multiplier: number | null; url: string | null }>;
    competitor_winners: Array<{ id: number | null; handle: string | null; caption: string; multiplier: number | null; why: string | null }>;
    what_changed: string[];
    next_focus: Array<{ format: string; hook: string; why: string | null }>;
};

const props = defineProps<{
    report: Report | null;
    shareUrl: string | null;
    shareToken: string | null;
    month: string;
    months: string[];
}>();

const kpiLabels: Record<string, string> = {
    followers: 'Followers',
    posts: 'Posts',
    engagement_rate: 'Engagement rate',
    avg_multiplier: 'Avg X× usual',
};

const monthOptions = computed(() => {
    const set = new Set(props.months);
    set.add(props.month);

    return [...set].sort().reverse();
});

function changeMonth(value: string): void {
    router.get(MonthlyReportController.show.url({ query: { month: value } }), {}, { preserveState: true });
}

function formatChange(value: number | null): string {
    if (value === null) {
        return '-';
    }

    const sign = value > 0 ? '+' : '';

    return `${sign}${value}%`;
}

async function copyShare(): Promise<void> {
    if (!props.shareUrl) {
        return;
    }

    await navigator.clipboard.writeText(props.shareUrl);
}

function printPdf(): void {
    if (!props.report) {
        return;
    }

    window.open(MonthlyReportController.pdf.url(props.report.id), '_blank');
}
</script>

<template>
    <div class="snitch-app-shell mx-auto max-w-4xl space-y-4 px-3 py-4 sm:px-4">
        <Head :title="report?.month_label ? `Report · ${report.month_label}` : 'Monthly report'" />

        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="snitch-ink-label">Monthly report</p>
                <h1 class="font-display text-2xl text-snitch-ink">
                    {{ report?.month_label ?? 'Pick a month' }}
                </h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a :href="GrowthController.index.url()" class="text-sm text-snitch-ink/70 underline-offset-2 hover:underline">
                    Growth charts
                </a>
                <select
                    class="rounded border border-snitch-ink/20 bg-snitch-paper px-2 py-1 text-sm"
                    :value="month"
                    @change="changeMonth(($event.target as HTMLSelectElement).value)"
                >
                    <option v-for="row in monthOptions" :key="row" :value="row">
                        {{ row }}
                    </option>
                </select>
            </div>
        </header>

        <div v-if="report" class="report-actions flex flex-wrap gap-2 print:hidden">
            <Form
                v-bind="MonthlyReportController.share.form(report.id)"
                class="inline"
            >
                <button type="submit" class="snitch-btn inline-flex items-center gap-1.5 text-sm">
                    <Link2 class="size-3.5" />
                    Share link
                </button>
            </Form>
            <Form
                v-if="shareUrl"
                v-bind="MonthlyReportController.revoke.form(report.id)"
                class="inline"
            >
                <button type="submit" class="inline-flex items-center gap-1.5 rounded border border-snitch-ink/20 px-3 py-1.5 text-sm">
                    <Link2Off class="size-3.5" />
                    Revoke
                </button>
            </Form>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded border border-snitch-ink/20 px-3 py-1.5 text-sm"
                @click="printPdf"
            >
                <Download class="size-3.5" />
                Download PDF
            </button>
        </div>

        <p v-if="shareUrl" class="print:hidden break-all rounded border border-snitch-ink/15 bg-snitch-paper px-3 py-2 text-xs text-snitch-ink/70">
            {{ shareUrl }}
            <button type="button" class="ml-2 underline" @click="copyShare">Copy</button>
        </p>

        <template v-if="report">
            <section class="grid gap-2 sm:grid-cols-2">
                <article
                    v-for="(label, key) in kpiLabels"
                    :key="key"
                    class="snitch-scrap p-3"
                >
                    <p class="snitch-ink-label">{{ label }}</p>
                    <p class="mt-1 font-display text-2xl text-snitch-ink">
                        {{ report.kpis[key]?.you ?? '-' }}
                    </p>
                    <p class="text-xs text-snitch-ink/60">
                        vs last month: {{ formatChange(report.kpis[key]?.you_change ?? null) }}
                        · peer {{ report.kpis[key]?.peer ?? '-' }}
                        ({{ formatChange(report.kpis[key]?.peer_change ?? null) }})
                    </p>
                </article>
            </section>

            <section class="snitch-scrap space-y-2 p-3">
                <h2 class="font-display text-lg text-snitch-ink">Your top 3</h2>
                <ul class="space-y-2">
                    <li
                        v-for="post in report.own_top_posts"
                        :key="post.id"
                        class="border-b border-snitch-ink/10 pb-2 text-sm last:border-0"
                    >
                        <span v-if="post.multiplier" class="snitch-ink-label mr-2">{{ post.multiplier }}×</span>
                        {{ post.caption || 'Untitled post' }}
                    </li>
                    <li v-if="!report.own_top_posts.length" class="text-sm text-snitch-ink/55">
                        No own posts in this month yet.
                    </li>
                </ul>
            </section>

            <section class="snitch-scrap space-y-2 p-3">
                <h2 class="font-display text-lg text-snitch-ink">Competitor winners</h2>
                <ul class="space-y-2">
                    <li
                        v-for="(winner, index) in report.competitor_winners"
                        :key="winner.id ?? index"
                        class="border-b border-snitch-ink/10 pb-2 text-sm last:border-0"
                    >
                        <span class="snitch-ink-label mr-2">
                            @{{ winner.handle ?? 'rival' }}
                            <template v-if="winner.multiplier"> · {{ winner.multiplier }}×</template>
                        </span>
                        {{ winner.caption || winner.why || 'Winner post' }}
                    </li>
                    <li v-if="!report.competitor_winners.length" class="text-sm text-snitch-ink/55">
                        No scored rival winners this month.
                    </li>
                </ul>
            </section>

            <section class="snitch-scrap space-y-2 p-3">
                <h2 class="font-display text-lg text-snitch-ink">What changed</h2>
                <ul class="list-disc space-y-1 pl-5 text-sm text-snitch-ink/80">
                    <li v-for="line in report.what_changed" :key="line">{{ line }}</li>
                </ul>
            </section>

            <section class="snitch-scrap space-y-2 p-3">
                <h2 class="font-display text-lg text-snitch-ink">Next month focus</h2>
                <ul class="space-y-2">
                    <li
                        v-for="(idea, index) in report.next_focus"
                        :key="index"
                        class="text-sm"
                    >
                        <span class="snitch-ink-label">{{ idea.format }}</span>
                        <p class="font-medium text-snitch-ink">{{ idea.hook }}</p>
                        <p class="text-snitch-ink/65">{{ idea.why }}</p>
                    </li>
                    <li v-if="!report.next_focus.length" class="text-sm text-snitch-ink/55">
                        Generate a weekly brief to pull focus ideas here.
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
