<script setup lang="ts">
import { computed } from 'vue';
import { postTypeLabel } from '@/lib/posts';

export type FormatMixRow = {
    type: string;
    count: number;
};

const props = defineProps<{
    formats: FormatMixRow[];
    lifts?: Record<string, number | null>;
}>();

function liftFor(type: string): number | null {
    const lifts = props.lifts ?? {};
    const direct = lifts[type];

    if (typeof direct === 'number' && Number.isFinite(direct)) {
        return direct;
    }

    const titled = type.charAt(0).toUpperCase() + type.slice(1).toLowerCase();
    const titledValue = lifts[titled];

    return typeof titledValue === 'number' && Number.isFinite(titledValue) ? titledValue : null;
}

function liftLabel(type: string): string | null {
    const value = liftFor(type);

    return value == null ? null : `${value.toFixed(1)}×`;
}

function barWidth(count: number): string {
    return `${Math.max((count / maxCount.value) * 100, count > 0 ? 4 : 0)}%`;
}

const maxCount = computed(() =>
    Math.max(1, ...props.formats.map((row) => row.count)),
);

const total = computed(() =>
    props.formats.reduce((sum, row) => sum + row.count, 0),
);

const peakByLift = computed(() => {
    let best: { type: string; lift: number; count: number } | null = null;

    for (const row of props.formats) {
        const lift = liftFor(row.type);

        if (lift == null || row.count <= 0) {
            continue;
        }

        if (best == null || lift > best.lift) {
            best = { type: row.type, lift, count: row.count };
        }
    }

    return best;
});

const tip = computed(() => {
    if (! peakByLift.value) {
        return null;
    }

    const label = postTypeLabel(peakByLift.value.type);

    return `Rivals get ${peakByLift.value.lift.toFixed(1)}× their usual from ${label}.`;
});
</script>

<template>
    <div class="snitch-format-mix flex h-full min-h-0 flex-col">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <div>
                <p class="snitch-ink-label">Format vs results</p>
            </div>
            <p class="font-mono text-sm tabular-nums text-snitch-ink/55">
                {{ total }} posts
            </p>
        </div>

        <ul
            v-if="formats.length"
            class="mt-3 grid flex-1 content-start gap-2 sm:grid-cols-2"
        >
            <li
                v-for="row in formats"
                :key="row.type"
                class="rounded border border-snitch-ink/10 bg-snitch-lift px-2.5 py-2"
            >
                <div class="flex items-baseline justify-between gap-2">
                    <span class="whitespace-nowrap text-sm font-medium text-snitch-ink">
                        {{ postTypeLabel(row.type) }}
                    </span>
                    <span class="font-mono text-sm tabular-nums text-snitch-ink/70">
                        {{ liftLabel(row.type) || 'no lift yet' }}
                    </span>
                </div>
                <div
                    class="snitch-meter mt-1.5 h-3"
                    :title="`${postTypeLabel(row.type)}: ${row.count} posts`"
                >
                    <div
                        class="h-full rounded-sm"
                        :class="peakByLift?.type === row.type ? 'snitch-meter-fill-lead' : 'snitch-meter-fill'"
                        :style="{ width: barWidth(row.count) }"
                    />
                </div>
                <p class="mt-1 font-mono text-sm tabular-nums text-snitch-ink/55">
                    {{ row.count }} posts
                </p>
            </li>
        </ul>
        <p v-else class="mt-2 text-sm text-snitch-ink/55">
            No posts yet.
        </p>
        <p
            v-if="tip"
            class="mt-auto pt-3 rounded border border-snitch-spot/60 bg-snitch-spot/35 px-2.5 py-2 text-sm text-snitch-ink"
        >
            {{ tip }}
        </p>
    </div>
</template>
