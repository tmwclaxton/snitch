<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ExternalLink, X } from '@lucide/vue';
import { computed } from 'vue';
import { index as competitors } from '@/actions/App/Http/Controllers/CompetitorController';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: AppLayout,
});

type Account = {
    id: number;
    handle: string;
    display_name: string | null;
    avatar: string | null;
    followers: number;
    is_own_account: boolean;
    posts_count: number;
};

type Phrase = { text: string; count: number };

const props = defineProps<{
    own_account: Account | null;
    rivals: Account[];
    selected: string[];
    max_compare: number;
    headline: string | null;
    insights: {
        bestTimes: { day: string; hour: number }[];
        formatStats: { format: string }[];
        bestLength: { label: string } | null;
        patternLifts: { label: string }[];
        hasAnyData: boolean;
    };
    gaps: { label: string; detail: string }[];
    kpis: {
        avg_er: number;
        posts: number;
        posts_week: number;
        avg_likes: number;
        avg_comments: number;
    };
    compare: {
        handle: string;
        avatar: string | null;
        followers: number;
        avg_er: number;
        posts: number;
        posts_week: number;
        avg_likes: number;
        avg_comments: number;
    }[];
    top_posts: {
        id: number;
        handle: string | null;
        caption: string | null;
        likes: number;
        comments: number;
        er: number;
        thumbnail_url: string | null;
        url: string | null;
    }[];
    heatmap: number[][];
    format_split: { handle: string; count: number; reels: number; carousels: number; images: number }[];
    phrases: { handle: string; avatar: string | null; words: Phrase[]; bigrams: Phrase[] }[];
}>();

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const mode = computed(() => (props.selected.length > 1 ? 'compare' : 'single'));
const heatMax = computed(() => Math.max(1, ...props.heatmap.flat()));
const maxFormat = computed(() => Math.max(1, ...props.format_split.map((row) => row.count)));

function toggle(handle: string): void {
    const current = [...props.selected];
    const exists = current.includes(handle);

    if (exists && current.length === 1) {
        return;
    }

    const next = exists
        ? current.filter((value) => value !== handle)
        : current.length >= props.max_compare
            ? current
            : [...current, handle];

    router.get(dashboard.url({ query: { accounts: next.join(',') } }), {}, { preserveState: true, replace: true });
}

function pct(part: number, total: number): number {
    return total > 0 ? Math.round((part / total) * 100) : 0;
}
</script>

<template>
    <div class="px-4 py-6 sm:px-8">
        <Head title="Dashboard" />
        <div class="mb-6 border-b border-neutral-200 pb-4">
            <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">Dashboard</h1>
        </div>

        <div v-if="rivals.length === 0" class="border border-dashed border-neutral-200 bg-white p-12 text-center">
            <h2 class="text-lg font-semibold">No competitors yet</h2>
            <p class="mt-2 text-sm text-neutral-500">Add an Instagram handle to start snitching.</p>
            <Link
                :href="competitors()"
                class="mt-6 inline-block bg-neutral-950 px-4 py-2 text-sm text-white hover:opacity-90"
            >
                Add competitor
            </Link>
        </div>

        <div v-else class="space-y-8">
            <div v-if="headline" class="border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm">
                {{ headline }}
            </div>

            <section v-if="gaps.length && own_account">
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-neutral-500">
                    Gaps on @{{ own_account.handle }}
                </h2>
                <ul class="divide-y divide-neutral-200 border border-neutral-200 bg-white">
                    <li v-for="gap in gaps" :key="gap.label" class="p-4">
                        <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ gap.label }}</div>
                        <p class="mt-1 text-sm">{{ gap.detail }}</p>
                    </li>
                </ul>
            </section>

            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-neutral-500">What the data shows</h2>
                <div class="grid grid-cols-1 gap-px border border-neutral-200 bg-neutral-200 sm:grid-cols-3">
                    <div class="bg-white p-4">
                        <div class="mb-3 text-[10px] uppercase tracking-wider text-neutral-500">Post times currently performing best</div>
                        <p v-if="!insights.bestTimes.length" class="text-xs text-neutral-500">Not enough data yet.</p>
                        <ul v-else class="space-y-2 text-sm">
                            <li v-for="time in insights.bestTimes" :key="`${time.day}-${time.hour}`">
                                {{ time.day }} {{ time.hour }}:00
                            </li>
                        </ul>
                    </div>
                    <div class="bg-white p-4">
                        <div class="mb-3 text-[10px] uppercase tracking-wider text-neutral-500">Formats currently performing best</div>
                        <p v-if="!insights.formatStats.length" class="text-xs text-neutral-500">Not enough data yet.</p>
                        <ul v-else class="space-y-2 text-sm">
                            <li v-for="format in insights.formatStats" :key="format.format">{{ format.format }}</li>
                        </ul>
                    </div>
                    <div class="bg-white p-4">
                        <div class="mb-3 text-[10px] uppercase tracking-wider text-neutral-500">Caption insights</div>
                        <div v-if="insights.bestLength" class="text-sm">{{ insights.bestLength.label }} chars</div>
                        <p v-else class="text-xs text-neutral-500">Not enough data.</p>
                        <ul v-if="insights.patternLifts.length" class="mt-3 space-y-1 text-xs">
                            <li v-for="pattern in insights.patternLifts" :key="pattern.label">{{ pattern.label }}</li>
                        </ul>
                    </div>
                </div>
            </section>

            <div class="flex items-center gap-2 overflow-x-auto border border-neutral-200 bg-white p-3">
                <button
                    v-for="rival in rivals"
                    :key="rival.id"
                    type="button"
                    class="inline-flex shrink-0 items-center gap-2 border border-neutral-200 px-2 py-1 text-xs"
                    :class="selected.includes(rival.handle.toLowerCase()) ? 'bg-neutral-950 text-white' : 'hover:bg-neutral-50'"
                    @click="toggle(rival.handle.toLowerCase())"
                >
                    @{{ rival.handle }}
                    <X v-if="selected.includes(rival.handle.toLowerCase())" class="h-3 w-3" />
                </button>
            </div>

            <div v-if="mode === 'compare'" class="overflow-x-auto border border-neutral-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase tracking-wider text-neutral-500">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">Metric</th>
                            <th v-for="row in compare" :key="row.handle" class="px-4 py-2 text-right font-medium">
                                @{{ row.handle }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        <tr>
                            <td class="px-4 py-3 text-xs uppercase tracking-wider text-neutral-500">Followers</td>
                            <td v-for="row in compare" :key="row.handle + 'f'" class="px-4 py-3 text-right tabular-nums">{{ row.followers.toLocaleString() }}</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-xs uppercase tracking-wider text-neutral-500">Avg engagement rate</td>
                            <td v-for="row in compare" :key="row.handle + 'er'" class="px-4 py-3 text-right tabular-nums">{{ row.avg_er.toFixed(2) }}%</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-3 text-xs uppercase tracking-wider text-neutral-500">Posts last week</td>
                            <td v-for="row in compare" :key="row.handle + 'w'" class="px-4 py-3 text-right tabular-nums">{{ row.posts_week }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="grid gap-px border border-neutral-200 bg-neutral-200 sm:grid-cols-5">
                <div class="bg-white p-4">
                    <div class="text-xs uppercase tracking-wider text-neutral-500">Avg engagement rate</div>
                    <div class="mt-2 text-2xl font-semibold tabular-nums">{{ kpis.avg_er.toFixed(2) }}%</div>
                </div>
                <div class="bg-white p-4">
                    <div class="text-xs uppercase tracking-wider text-neutral-500">Posts pulled</div>
                    <div class="mt-2 text-2xl font-semibold tabular-nums">{{ kpis.posts }}</div>
                </div>
                <div class="bg-white p-4">
                    <div class="text-xs uppercase tracking-wider text-neutral-500">Posts last week</div>
                    <div class="mt-2 text-2xl font-semibold tabular-nums">{{ kpis.posts_week }}</div>
                </div>
                <div class="bg-white p-4">
                    <div class="text-xs uppercase tracking-wider text-neutral-500">Avg likes / post</div>
                    <div class="mt-2 text-2xl font-semibold tabular-nums">{{ kpis.avg_likes.toLocaleString() }}</div>
                </div>
                <div class="bg-white p-4">
                    <div class="text-xs uppercase tracking-wider text-neutral-500">Avg comments / post</div>
                    <div class="mt-2 text-2xl font-semibold tabular-nums">{{ kpis.avg_comments.toLocaleString() }}</div>
                </div>
            </div>

            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-neutral-500">Top posts last week</h2>
                <p v-if="!top_posts.length" class="text-sm text-neutral-500">No posts in the last 7 days yet.</p>
                <div v-else class="grid gap-px border border-neutral-200 bg-neutral-200 sm:grid-cols-3">
                    <div v-for="(post, index) in top_posts" :key="post.id" class="flex gap-4 bg-white p-4">
                        <div class="text-3xl text-neutral-400">{{ index + 1 }}</div>
                        <img
                            v-if="post.thumbnail_url"
                            :src="post.thumbnail_url"
                            alt=""
                            class="h-20 w-20 border border-neutral-200 object-cover"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="text-xs text-neutral-500">@{{ post.handle }}</div>
                            <div class="mt-1 line-clamp-2 text-sm">{{ post.caption || 'No caption' }}</div>
                            <div class="mt-2 flex flex-wrap gap-3 text-xs tabular-nums">
                                <span>{{ post.likes.toLocaleString() }} likes</span>
                                <span>{{ post.comments.toLocaleString() }} comments</span>
                                <span class="font-semibold">{{ post.er.toFixed(2) }}% ER</span>
                                <a v-if="post.url" :href="post.url" target="_blank" rel="noreferrer" class="ml-auto inline-flex items-center gap-1 underline">
                                    View <ExternalLink class="h-3 w-3" />
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section>
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-neutral-500">When competitors post</h2>
                    <div class="overflow-x-auto border border-neutral-200 bg-white p-3">
                        <div class="min-w-[520px]">
                            <div class="flex">
                                <div class="w-10" />
                                <div class="grid flex-1 grid-cols-24 gap-px">
                                    <div v-for="hour in 24" :key="hour" class="text-center text-[9px] text-neutral-400">
                                        {{ (hour - 1) % 3 === 0 ? hour - 1 : '' }}
                                    </div>
                                </div>
                            </div>
                            <div v-for="(day, dayIndex) in DAYS" :key="day" class="mt-1 flex">
                                <div class="w-10 text-xs text-neutral-500">{{ day }}</div>
                                <div class="grid flex-1 grid-cols-24 gap-px">
                                    <div
                                        v-for="(value, hour) in heatmap[dayIndex]"
                                        :key="hour"
                                        class="aspect-square border border-neutral-200"
                                        :style="{ backgroundColor: value === 0 ? 'transparent' : `oklch(${1 - (value / heatMax) * 0.95} 0 0)` }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section>
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-neutral-500">Format split</h2>
                    <div class="divide-y divide-neutral-200 border border-neutral-200 bg-white">
                        <div v-for="row in format_split" :key="row.handle" class="p-3">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm">@{{ row.handle }}</span>
                                <span class="text-sm tabular-nums">{{ row.count }} posts</span>
                            </div>
                            <div class="mt-2 h-2 bg-neutral-100">
                                <div class="h-full bg-neutral-950" :style="{ width: `${(row.count / maxFormat) * 100}%` }" />
                            </div>
                            <div v-if="row.count > 0" class="mt-1.5 flex flex-wrap gap-3 text-[10px] text-neutral-500">
                                <span>Reels {{ pct(row.reels, row.count) }}%</span>
                                <span>Carousels {{ pct(row.carousels, row.count) }}%</span>
                                <span>Images {{ pct(row.images, row.count) }}%</span>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-neutral-500">Most-used words & phrases in captions</h2>
                <div class="grid grid-cols-1 gap-px border border-neutral-200 bg-neutral-200 sm:grid-cols-2">
                    <div v-for="row in phrases" :key="row.handle" class="bg-white p-4">
                        <div class="mb-3 text-xs uppercase tracking-wider text-neutral-500">@{{ row.handle }}</div>
                        <div class="grid grid-cols-2 gap-4 text-xs">
                            <div>
                                <div class="mb-2 text-[10px] uppercase text-neutral-400">Words</div>
                                <ul class="space-y-1">
                                    <li v-for="word in row.words" :key="word.text" class="flex justify-between gap-2">
                                        <span class="truncate">{{ word.text }}</span>
                                        <span class="tabular-nums text-neutral-500">{{ word.count }}</span>
                                    </li>
                                </ul>
                            </div>
                            <div>
                                <div class="mb-2 text-[10px] uppercase text-neutral-400">Phrases</div>
                                <ul class="space-y-1">
                                    <li v-for="phrase in row.bigrams" :key="phrase.text" class="flex justify-between gap-2">
                                        <span class="truncate">{{ phrase.text }}</span>
                                        <span class="tabular-nums text-neutral-500">{{ phrase.count }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
