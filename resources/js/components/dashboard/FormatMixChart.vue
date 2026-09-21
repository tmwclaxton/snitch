<script setup lang="ts">
import { computed } from 'vue';
import PlatformStippleTrack from '@/components/PlatformStippleTrack.vue';
import { postTypeLabel } from '@/lib/posts';

export type FormatMixRow = {
    type: string;
    count: number;
};

const props = defineProps<{
    formats: FormatMixRow[];
}>();

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
            <p class="tabular-nums text-xs text-snitch-ink/55">
                {{ total }} posts
            </p>
        </div>

        <ul v-if="formats.length" class="mt-4 space-y-3">
            <li
                v-for="(row, index) in formats"
                :key="row.type"
                class="grid grid-cols-[6.5rem_minmax(0,1fr)_2.5rem] items-center gap-2"
            >
                <span class="truncate text-sm text-snitch-ink/75">
                    {{ postTypeLabel(row.type) }}
                </span>
                <PlatformStippleTrack
                    :count="row.count"
                    :max-count="maxCount"
                    :is-peak="index === peakIndex && row.count > 0"
                    :seed="index + 7"
                    :delay-offset="index * 28"
                    :title="`${postTypeLabel(row.type)}: ${row.count}`"
                />
                <span class="text-right text-sm tabular-nums text-snitch-ink/70">
                    {{ row.count }}
                </span>
            </li>
        </ul>
        <p v-else class="mt-4 text-sm text-snitch-ink/55">
            No posts yet.
        </p>
    </div>
</template>
