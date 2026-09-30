<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/layouts/PublicLayout.vue';

defineOptions({
    layout: PublicLayout,
});

type Kpi = {
    you: number | null;
    you_display: string;
    you_change: number | null;
    you_change_label: string;
    peer: number | null;
    peer_display: string;
    peer_change: number | null;
    peer_change_label: string;
};

type PostRow = {
    caption: string;
    caption_preview?: string;
    multiplier: number | null;
    thumbnail_url?: string | null;
};

type WinnerRow = {
    handle: string | null;
    caption: string;
    caption_preview?: string;
    multiplier: number | null;
    thumbnail_url?: string | null;
    why?: string | null;
};

type Report = {
    month_label: string;
    prev_month_label?: string;
    kpis: Record<string, Kpi>;
    own_top_posts: PostRow[];
    competitor_winners: WinnerRow[];
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

function captionText(row: { caption: string; caption_preview?: string }): string {
    return row.caption || row.caption_preview || 'Untitled';
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
                <p class="mt-1 font-display text-2xl">{{ report.kpis[key]?.you_display ?? 'no data' }}</p>
                <p class="mt-1 text-sm text-snitch-ink/60">
                    vs last month:
                    <span :class="report.kpis[key]?.you_change == null ? 'text-snitch-ink/45' : ''">
                        {{ report.kpis[key]?.you_change_label ?? 'no data' }}
                    </span>
                    · rivals' average
                    {{ report.kpis[key]?.peer_display ?? 'no data' }}
                    <span :class="report.kpis[key]?.peer_change == null ? 'text-snitch-ink/45' : ''">
                        ({{ report.kpis[key]?.peer_change_label ?? 'no data' }})
                    </span>
                </p>
            </article>
        </section>

        <section class="snitch-scrap space-y-2 p-3">
            <h2 class="font-display text-lg">Your top posts</h2>
            <div
                v-for="(post, index) in report.own_top_posts"
                :key="index"
                class="flex gap-3 text-sm"
            >
                <img
                    v-if="post.thumbnail_url"
                    :src="post.thumbnail_url"
                    alt=""
                    class="size-12 shrink-0 object-cover"
                >
                <span
                    v-else
                    class="flex size-12 shrink-0 items-center justify-center bg-snitch-ink/10 text-[10px] text-snitch-ink/40"
                >
                    Post
                </span>
                <div class="min-w-0">
                    <p class="snitch-ink-label">
                        <template v-if="post.multiplier != null">{{ post.multiplier.toFixed(1) }}×</template>
                        <template v-else>No X× yet</template>
                    </p>
                    <p class="mt-0.5 whitespace-pre-wrap">{{ captionText(post) }}</p>
                </div>
            </div>
        </section>

        <section class="snitch-scrap space-y-2 p-3">
            <h2 class="font-display text-lg">Competitor winners</h2>
            <div
                v-for="(winner, index) in report.competitor_winners"
                :key="index"
                class="flex gap-3 text-sm"
            >
                <img
                    v-if="winner.thumbnail_url"
                    :src="winner.thumbnail_url"
                    alt=""
                    class="size-12 shrink-0 object-cover"
                >
                <span
                    v-else
                    class="flex size-12 shrink-0 items-center justify-center bg-snitch-ink/10 text-[10px] text-snitch-ink/40"
                >
                    Post
                </span>
                <div class="min-w-0">
                    <p class="snitch-ink-label">
                        @{{ winner.handle ?? 'rival' }}
                        <template v-if="winner.multiplier != null"> · {{ winner.multiplier.toFixed(1) }}×</template>
                    </p>
                    <p class="mt-0.5 whitespace-pre-wrap">
                        {{ captionText(winner) || winner.why || 'Winner post' }}
                    </p>
                </div>
            </div>
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
