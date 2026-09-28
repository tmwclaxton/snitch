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

    return value == null ? null : `${value.toFixed(1)}x`;
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

const peakIndex = computed(() => {
    let peak = 0;
    let peakCount = -1;

    props.formats.forEach((row, index) => {
        if (row.count > peakCount) {
            peakCount = row.count;
            peak = index;
        }
    });

    return peak;
});
</script>

<template>
    <div class="snitch-format-mix">
        <div class="flex items-baseline justify-between gap-3">
            <p class="snitch-ink-label">Format mix</p>
            <p class="tabular-nums text-xs text-slate-500">
                {{ total }} posts
            </p>
        </div>

        <ul v-if="formats.length" class="mt-1.5 space-y-1.5">
            <li
                v-for="(row, index) in formats"
                :key="row.type"
                class="grid grid-cols-[5.5rem_minmax(0,1fr)_2rem_2.75rem] items-center gap-1.5"
            >
                <span class="whitespace-nowrap text-[11px] text-slate-600">
                    {{ postTypeLabel(row.type) }}
                </span>
                <div
                    class="h-2 overflow-hidden rounded-sm bg-slate-100"
                    :title="`${postTypeLabel(row.type)}: ${row.count}`"
                >
                    <div
                        class="h-full rounded-sm"
                        :class="index === peakIndex && row.count > 0 ? 'bg-slate-800' : 'bg-slate-500'"
                        :style="{ width: barWidth(row.count) }"
                    />
                </div>
                <span class="text-right text-[11px] tabular-nums text-slate-600">
                    {{ row.count }}
                </span>
                <span
                    class="text-right text-[10px] tabular-nums text-slate-500"
                    :title="liftLabel(row.type) ? 'Peer median lift vs account usual' : undefined"
                >
                    {{ liftLabel(row.type) || '-' }}
                </span>
            </li>
        </ul>
        <p v-else class="mt-2 text-sm text-slate-500">
            No posts yet.
        </p>
    </div>
</template>
