<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { CalendarDays, Check, Lightbulb, LoaderCircle, RefreshCw } from '@lucide/vue';
import { computed } from 'vue';
import BriefController from '@/actions/App/Http/Controllers/BriefController';
import { show as feedShow } from '@/actions/App/Http/Controllers/FeedController';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout,
});

type BestTime = {
    day: string;
    hour: number;
    label: string;
    score: number;
};

type Idea = {
    id: number;
    position: number;
    format: string;
    hook: string;
    caption_angle: string;
    cta: string | null;
    hashtags: string[];
    recommended_day: string | null;
    recommended_hour: number | null;
    why: string | null;
    used_at: string | null;
    inspired_by: Array<{ id: number; url: string | null }>;
};

type Brief = {
    id: number;
    week_start: string;
    status: string;
    best_times: BestTime[];
    heat_grid: Array<Array<number | null>>;
    thin_data: boolean;
    was_free: boolean;
    credits_charged_pence: number;
    generated_at: string | null;
    ideas: Idea[];
};

type HistoryRow = {
    id: number;
    week_start: string;
    generated_at: string | null;
    was_free: boolean;
    credits_charged_pence: number;
};

const props = defineProps<{
    brief: Brief | null;
    history: HistoryRow[];
    weekStart: string;
    creditCost: number;
    generating: boolean;
}>();

const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const heatMax = computed(() => {
    let max = 0;

    for (const row of props.brief?.heat_grid ?? []) {
        for (const cell of row) {
            if (typeof cell === 'number' && cell > max) {
                max = cell;
            }
        }
    }

    return max || 1;
});

function heatStyle(value: number | null): Record<string, string> {
    if (value == null) {
        return { background: 'transparent' };
    }

    const alpha = Math.min(0.85, 0.15 + (value / heatMax.value) * 0.7);

    return { background: `rgba(240, 196, 0, ${alpha})` };
}

function formatHour(hour: number | null): string {
    if (hour == null) {
        return '';
    }

    return `${String(hour).padStart(2, '0')}:00`;
}

function generate(force = false): void {
    router.post(BriefController.generate.url(), { force }, { preserveScroll: true });
}
</script>

<template>
    <div class="snitch-app-shell relative min-h-full px-2 py-6 sm:px-3 sm:py-8">
        <Head title="This week" />
        <div class="snitch-grain" aria-hidden="true" />

        <div class="snitch-app-canvas space-y-6">
            <header class="flex flex-wrap items-end justify-between gap-4 border-b border-snitch-ink/10 pb-5">
                <div>
                    <p class="snitch-ink-label">Post this next</p>
                    <h1 class="snitch-display mt-1 text-3xl text-snitch-ink sm:text-4xl">
                        This week
                    </h1>
                    <p class="mt-1.5 max-w-2xl text-sm text-snitch-ink/65">
                        Three ideas grounded in competitor winners and your brand.
                        Week of {{ weekStart }}.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="!brief"
                        type="button"
                        class="snitch-btn snitch-btn-spot"
                        :disabled="generating"
                        @click="generate(false)"
                    >
                        <span class="relative z-10 inline-flex items-center gap-2">
                            <LoaderCircle
                                v-if="generating"
                                class="size-4 animate-spin"
                            />
                            <Lightbulb
                                v-else
                                class="size-4"
                            />
                            Generate free brief
                        </span>
                    </button>
                    <button
                        v-else
                        type="button"
                        class="snitch-btn snitch-btn-ghost"
                        :disabled="generating"
                        @click="generate(true)"
                    >
                        <span class="relative z-10 inline-flex items-center gap-2">
                            <RefreshCw class="size-4" />
                            Regenerate ({{ Math.round(creditCost) }} credits)
                        </span>
                    </button>
                </div>
            </header>

            <section
                v-if="brief"
                class="snitch-scrap relative space-y-4 p-5 pt-6 sm:p-6"
            >
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="snitch-display text-2xl text-snitch-ink">Best times</h2>
                    <p
                        v-if="brief.thin_data"
                        class="text-sm text-snitch-ink/60"
                    >
                        Not enough posts yet for a strong ranking - treat these as rough.
                    </p>
                </div>
                <ol
                    v-if="brief.best_times.length"
                    class="grid gap-2 sm:grid-cols-3"
                >
                    <li
                        v-for="(slot, index) in brief.best_times"
                        :key="slot.label"
                        class="border border-snitch-ink/10 bg-snitch-paper/50 px-3 py-2"
                    >
                        <p class="text-xs uppercase tracking-wide text-snitch-ink/45">
                            #{{ index + 1 }}
                        </p>
                        <p class="font-medium text-snitch-ink">
                            {{ slot.label }}
                        </p>
                        <p class="text-xs text-snitch-ink/55">
                            {{ slot.score.toFixed(1) }}× median
                        </p>
                    </li>
                </ol>
                <p
                    v-else
                    class="text-sm text-snitch-ink/60"
                >
                    No timing signal yet. Sync more posts and try again.
                </p>
                <div
                    v-if="brief.heat_grid?.length"
                    class="overflow-x-auto"
                >
                    <div
                        class="inline-grid gap-px"
                        :style="{ gridTemplateColumns: `2rem repeat(24, minmax(0.55rem, 1fr))` }"
                    >
                        <span />
                        <span
                            v-for="hour in 24"
                            :key="`h-${hour}`"
                            class="text-center text-[8px] text-snitch-ink/40"
                        >
                            {{ hour - 1 }}
                        </span>
                        <template
                            v-for="(row, dow) in brief.heat_grid"
                            :key="`d-${dow}`"
                        >
                            <span class="pr-1 text-right text-[10px] text-snitch-ink/50">
                                {{ days[dow] }}
                            </span>
                            <span
                                v-for="(cell, hour) in row"
                                :key="`c-${dow}-${hour}`"
                                class="size-2.5 border border-snitch-ink/5"
                                :style="heatStyle(cell)"
                                :title="cell == null ? '' : `${days[dow]} ${hour}:00 · ${cell}×`"
                            />
                        </template>
                    </div>
                </div>
            </section>

            <section
                v-if="brief?.ideas?.length"
                class="space-y-4"
            >
                <article
                    v-for="idea in brief.ideas"
                    :key="idea.id"
                    class="snitch-scrap relative p-5 pt-6 sm:p-6"
                    :class="idea.used_at ? 'opacity-70' : ''"
                >
                    <span class="snitch-tape right-6 -top-2" aria-hidden="true" />
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="snitch-ink-label">
                                Idea {{ idea.position }} · {{ idea.format }}
                            </p>
                            <h3 class="snitch-display mt-1 text-2xl text-snitch-ink">
                                {{ idea.hook }}
                            </h3>
                        </div>
                        <Form
                            v-bind="BriefController.markUsed.form(idea.id)"
                            class="shrink-0"
                        >
                            <button
                                type="submit"
                                class="snitch-btn snitch-btn-ghost px-3 py-1.5 text-xs"
                            >
                                <span class="relative z-10 inline-flex items-center gap-1">
                                    <Check class="size-3.5" />
                                    {{ idea.used_at ? 'Mark unused' : 'Mark used' }}
                                </span>
                            </button>
                        </Form>
                    </div>
                    <p class="mt-3 text-sm text-snitch-ink/80">
                        {{ idea.caption_angle }}
                    </p>
                    <p
                        v-if="idea.cta"
                        class="mt-2 text-sm font-medium text-snitch-ink"
                    >
                        CTA: {{ idea.cta }}
                    </p>
                    <div
                        v-if="idea.hashtags.length"
                        class="mt-3 flex flex-wrap gap-1.5"
                    >
                        <span
                            v-for="tag in idea.hashtags"
                            :key="tag"
                            class="rounded-sm bg-snitch-ink/5 px-1.5 py-0.5 text-xs text-snitch-ink/70"
                        >
                            {{ tag }}
                        </span>
                    </div>
                    <p class="mt-3 inline-flex items-center gap-1.5 text-xs text-snitch-ink/55">
                        <CalendarDays class="size-3.5" />
                        Post {{ idea.recommended_day }}
                        {{ formatHour(idea.recommended_hour) }}
                    </p>
                    <p
                        v-if="idea.why"
                        class="mt-2 text-sm text-snitch-ink/70"
                    >
                        {{ idea.why }}
                    </p>
                    <div
                        v-if="idea.inspired_by.length"
                        class="mt-3 flex flex-wrap gap-2 text-xs"
                    >
                        <span class="text-snitch-ink/45">Inspired by</span>
                        <Link
                            v-for="source in idea.inspired_by"
                            :key="source.id"
                            :href="feedShow.url(source.id)"
                            class="underline decoration-snitch-spot underline-offset-2"
                        >
                            /feed/{{ source.id }}
                        </Link>
                    </div>
                </article>
            </section>

            <section
                v-else-if="!brief"
                class="snitch-scrap relative mx-auto max-w-lg p-8 text-center"
            >
                <span class="snitch-tape left-8 -top-2" aria-hidden="true" />
                <Lightbulb class="mx-auto size-8 text-snitch-ink/35" />
                <p class="snitch-display mt-3 text-2xl">No brief yet this week</p>
                <p class="mt-2 text-sm text-snitch-ink/65">
                    Generate one for free after your Monday sync, or now.
                </p>
            </section>

            <section
                v-if="history.length"
                class="snitch-scrap relative p-5 pt-6 sm:p-6"
            >
                <span class="snitch-tape left-4 -top-2" aria-hidden="true" />
                <h2 class="snitch-display text-xl text-snitch-ink">Previous weeks</h2>
                <ul class="mt-3 divide-y divide-snitch-ink/10 text-sm">
                    <li
                        v-for="row in history"
                        :key="row.id"
                    >
                        <Link
                            :href="BriefController.index.url({ query: { week: row.week_start } })"
                            class="flex items-center justify-between gap-3 py-2 hover:bg-snitch-paper/40"
                        >
                            <span>Week of {{ row.week_start }}</span>
                            <span class="text-xs text-snitch-ink/50">
                                {{ row.was_free ? 'Free' : `${Math.round(row.credits_charged_pence)} credits` }}
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
