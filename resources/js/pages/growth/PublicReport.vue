<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({
    layout: PublicLayout,
});

type Kpi = {
    you: number | null;
    you_change: number | null;
    peer: number | null;
};

type Report = {
    month_label: string;
    kpis: Record<string, Kpi>;
    own_top_posts: Array<{ caption: string; multiplier: number | null }>;
    competitor_winners: Array<{ handle: string | null; caption: string; multiplier: number | null }>;
    what_changed: string[];
    next_focus: Array<{ format: string; hook: string; why: string | null }>;
};

defineProps<{
    report: Report;
    token: string;
}>();

const kpiLabels: Record<string, string> = {
    followers: 'Followers',
    posts: 'Posts',
    engagement_rate: 'Engagement rate',
    avg_multiplier: 'Avg X× usual',
};

function formatChange(value: number | null): string {
    if (value === null) {
        return '-';
    }

    const sign = value > 0 ? '+' : '';

    return `${sign}${value}%`;
}
</script>

<template>
    <div class="snitch-surface mx-auto max-w-3xl space-y-4 px-4 py-8">
        <Head :title="`Snitch report · ${report.month_label}`" />

        <header>
            <p class="snitch-ink-label">Shared monthly report</p>
            <h1 class="font-display text-3xl text-snitch-ink">{{ report.month_label }}</h1>
            <p class="mt-1 text-sm text-snitch-ink/60">Read-only snapshot from Snitch.</p>
        </header>

        <section class="grid gap-2 sm:grid-cols-2">
            <article
                v-for="(label, key) in kpiLabels"
                :key="key"
                class="snitch-scrap p-3"
            >
                <p class="snitch-ink-label">{{ label }}</p>
                <p class="mt-1 font-display text-2xl">{{ report.kpis[key]?.you ?? '-' }}</p>
                <p class="text-xs text-snitch-ink/60">
                    {{ formatChange(report.kpis[key]?.you_change ?? null) }} vs prior · peer {{ report.kpis[key]?.peer ?? '-' }}
                </p>
            </article>
        </section>

        <section class="snitch-scrap space-y-2 p-3">
            <h2 class="font-display text-lg">Your top posts</h2>
            <p
                v-for="(post, index) in report.own_top_posts"
                :key="index"
                class="text-sm"
            >
                <span v-if="post.multiplier" class="snitch-ink-label mr-1">{{ post.multiplier }}×</span>
                {{ post.caption || 'Untitled' }}
            </p>
        </section>

        <section class="snitch-scrap space-y-2 p-3">
            <h2 class="font-display text-lg">Competitor winners</h2>
            <p
                v-for="(winner, index) in report.competitor_winners"
                :key="index"
                class="text-sm"
            >
                @{{ winner.handle ?? 'rival' }}
                <template v-if="winner.multiplier"> · {{ winner.multiplier }}×</template>
                - {{ winner.caption }}
            </p>
        </section>

        <section class="snitch-scrap space-y-2 p-3">
            <h2 class="font-display text-lg">What changed</h2>
            <ul class="list-disc pl-5 text-sm">
                <li v-for="line in report.what_changed" :key="line">{{ line }}</li>
            </ul>
        </section>

        <section class="snitch-scrap space-y-2 p-3">
            <h2 class="font-display text-lg">Next focus</h2>
            <div v-for="(idea, index) in report.next_focus" :key="index" class="text-sm">
                <p class="snitch-ink-label">{{ idea.format }}</p>
                <p class="font-medium">{{ idea.hook }}</p>
                <p class="text-snitch-ink/65">{{ idea.why }}</p>
            </div>
        </section>
    </div>
</template>
