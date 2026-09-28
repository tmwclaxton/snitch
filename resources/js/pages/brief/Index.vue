<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { CalendarDays, Check, ChevronDown, Lightbulb, LoaderCircle, RefreshCw } from '@lucide/vue';
import { computed, ref } from 'vue';
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

type InspiredBy = {
    id: number;
    url: string | null;
    thumbnail_url: string | null;
    handle: string;
    pi: number | null;
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
    inspired_by: InspiredBy[];
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
    canRegenerate: boolean;
}>();

const page = usePage();
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin) || props.canRegenerate);
const historyOpen = ref(false);

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

const showHistoryMenu = computed(() => props.history.length >= 2);

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

function formatWeekLabel(isoDate: string | null | undefined): string {
    if (!isoDate) {
        return '';
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate);

    if (!match) {
        return isoDate;
    }

    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = months[Number(match[2]) - 1] ?? match[2];

    return `${Number(match[3])} ${month}`;
}

function hourLabel(hour: number): string {
    return hour % 3 === 0 ? String(hour) : '';
}

function regenerate(): void {
    if (!isAdmin.value) {
        return;
    }

    router.post(BriefController.generate.url(), { force: true }, { preserveScroll: true });
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
                        Week of {{ formatWeekLabel(weekStart) }}.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div
                        v-if="showHistoryMenu"
                        class="relative"
                    >
                        <button
                            type="button"
                            class="snitch-btn snitch-btn-ghost inline-flex items-center gap-1.5 px-3 py-1.5 text-sm"
                            @click="historyOpen = !historyOpen"
                        >
                            <span class="relative z-10 inline-flex items-center gap-1.5">
                                Previous weeks
                                <ChevronDown class="size-3.5" />
                            </span>
                        </button>
                        <div
                            v-if="historyOpen"
                            class="absolute right-0 z-20 mt-1 min-w-[14rem] border border-snitch-ink/15 bg-snitch-paper shadow-sm"
                        >
                            <Link
                                v-for="row in history"
                                :key="row.id"
                                :href="BriefController.index.url({ query: { week: row.week_start } })"
                                class="flex items-center justify-between gap-3 px-3 py-2 text-sm hover:bg-snitch-spot/20"
                                @click="historyOpen = false"
                            >
                                <span>Week of {{ formatWeekLabel(row.week_start) }}</span>
                                <span class="text-xs text-snitch-ink/50">
                                    {{ row.was_free ? 'Free' : `${Math.round(row.credits_charged_pence)} credits` }}
                                </span>
                            </Link>
                        </div>
                    </div>
                    <button
                        v-if="isAdmin && brief"
                        type="button"
                        class="snitch-btn snitch-btn-ghost"
                        :disabled="generating"
                        @click="regenerate"
                    >
                        <span class="relative z-10 inline-flex items-center gap-2">
                            <LoaderCircle
                                v-if="generating"
                                class="size-4 animate-spin"
                            />
                            <RefreshCw
                                v-else
                                class="size-4"
                            />
                            Regenerate ({{ Math.round(creditCost) }} credits)
                        </span>
                    </button>
                </div>
            </header>

            <section
                v-if="brief"
                class="snitch-scrap relative p-5 pt-6 sm:p-6"
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

                <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(11rem,13rem)_minmax(0,1fr)] lg:items-start">
                    <ol
                        v-if="brief.best_times.length"
                        class="flex flex-col gap-2"
                    >
                        <li
                            v-for="(slot, index) in brief.best_times"
                            :key="slot.label"
                            class="border border-snitch-ink/10 bg-snitch-paper/50 px-3 py-2.5"
                        >
                            <p class="text-xs uppercase tracking-wide text-snitch-ink/45">
                                #{{ index + 1 }}
                            </p>
                            <p class="text-sm font-medium text-snitch-ink">
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
                        class="w-full min-w-0"
                    >
                        <div
                            class="grid w-full gap-0.5"
                            :style="{ gridTemplateColumns: `2.25rem repeat(24, minmax(0, 1fr))` }"
                        >
                            <span />
                            <span
                                v-for="hour in 24"
                                :key="`h-${hour}`"
                                class="text-center text-xs leading-none text-snitch-ink/45"
                            >
                                {{ hourLabel(hour - 1) }}
                            </span>
                            <template
                                v-for="(row, dow) in brief.heat_grid"
                                :key="`d-${dow}`"
                            >
                                <span class="pr-1 text-right text-xs text-snitch-ink/55">
                                    {{ days[dow] }}
                                </span>
                                <span
                                    v-for="(cell, hour) in row"
                                    :key="`c-${dow}-${hour}`"
                                    class="aspect-square w-full min-h-4 border border-snitch-ink/5"
                                    :style="heatStyle(cell)"
                                    :title="cell == null ? '' : `${days[dow]} ${hour}:00 · ${cell}×`"
                                />
                            </template>
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-if="brief?.ideas?.length"
                class="grid gap-4 md:grid-cols-3 md:items-stretch"
            >
                <article
                    v-for="idea in brief.ideas"
                    :key="idea.id"
                    class="snitch-scrap relative flex h-full flex-col p-4 pt-5"
                    :class="idea.used_at ? 'opacity-70' : ''"
                >
                    <span class="snitch-tape right-5 -top-2" aria-hidden="true" />
                    <div class="flex items-start justify-between gap-2">
                        <span class="rounded-sm bg-snitch-spot/40 px-1.5 py-0.5 text-xs font-medium uppercase tracking-wide text-snitch-ink">
                            {{ idea.format }}
                        </span>
                        <Form
                            v-bind="BriefController.markUsed.form(idea.id)"
                            class="shrink-0"
                        >
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1 text-xs text-snitch-ink/55 hover:text-snitch-ink"
                            >
                                <Check class="size-3.5" />
                                {{ idea.used_at ? 'Unused' : 'Used' }}
                            </button>
                        </Form>
                    </div>
                    <h3 class="snitch-display mt-2 text-xl font-semibold text-snitch-ink">
                        {{ idea.hook }}
                    </h3>
                    <p class="mt-2 text-sm text-snitch-ink/80">
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
                        class="mt-2 flex flex-wrap gap-1.5"
                    >
                        <span
                            v-for="tag in idea.hashtags"
                            :key="tag"
                            class="rounded-sm bg-snitch-ink/5 px-1.5 py-0.5 text-xs text-snitch-ink/70"
                        >
                            {{ tag }}
                        </span>
                    </div>
                    <p class="mt-3 inline-flex items-center gap-1.5 rounded-full border border-snitch-ink/15 bg-snitch-paper/70 px-2 py-0.5 text-xs text-snitch-ink/70">
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
                        class="mt-auto flex flex-col gap-2 pt-3"
                    >
                        <p class="text-xs uppercase tracking-wide text-snitch-ink/45">Inspired by</p>
                        <Link
                            v-for="source in idea.inspired_by"
                            :key="source.id"
                            :href="feedShow.url(source.id)"
                            class="flex items-center gap-2 rounded border border-snitch-ink/10 bg-snitch-paper/60 p-1.5 hover:border-snitch-spot"
                        >
                            <img
                                v-if="source.thumbnail_url"
                                :src="source.thumbnail_url"
                                alt=""
                                class="size-10 shrink-0 object-cover"
                            >
                            <span
                                v-else
                                class="flex size-10 shrink-0 items-center justify-center bg-snitch-ink/10 text-[10px] text-snitch-ink/40"
                            >
                                Post
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-snitch-ink">
                                    @{{ source.handle }}
                                </span>
                                <span
                                    v-if="source.pi != null"
                                    class="block text-xs text-snitch-ink/55"
                                >
                                    {{ source.pi.toFixed(1) }}× their usual
                                </span>
                            </span>
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
                <p class="mt-3 text-sm text-snitch-ink/70">
                    Your first brief appears automatically once Snitch has enough competitor posts analysed.
                </p>
            </section>
        </div>
    </div>
</template>
