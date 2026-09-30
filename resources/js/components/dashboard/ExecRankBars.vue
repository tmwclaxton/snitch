<script setup lang="ts">
import { computed } from 'vue';

type Row = {
    handle: string;
    is_own_account?: boolean;
    er?: number | null;
    followers?: number | null;
    posts_per_week?: number | null;
};

const props = defineProps<{
    rows: Row[];
}>();

const ranked = computed(() => {
    const usable = props.rows
        .filter((row) => typeof row.er === 'number' && Number.isFinite(row.er))
        .map((row) => ({
            ...row,
            er: Number(row.er),
        }))
        .sort((a, b) => b.er - a.er);

    const max = Math.max(0.01, ...usable.map((row) => row.er));

    return usable.map((row) => ({
        ...row,
        width: `${Math.max(8, (row.er / max) * 100)}%`,
        label: row.is_own_account ? 'You' : `@${row.handle}`,
        erLabel: `${row.er.toFixed(1)}%`,
    }));
});
</script>

<template>
    <div
        v-if="ranked.length"
        class="space-y-2"
    >
        <p class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
            Engagement rank
        </p>
        <ul class="space-y-2">
            <li
                v-for="row in ranked"
                :key="row.handle"
                class="grid grid-cols-[minmax(8rem,11rem)_1fr_auto] items-center gap-2"
            >
                <span
                    class="whitespace-nowrap text-sm font-medium text-snitch-ink"
                    :class="row.is_own_account ? 'font-semibold' : ''"
                >
                    {{ row.label }}
                </span>
                <div class="h-3 overflow-hidden rounded-sm bg-snitch-ink/8">
                    <div
                        class="h-full rounded-sm"
                        :class="row.is_own_account ? 'bg-snitch-spot' : 'bg-snitch-ink'"
                        :style="{ width: row.width }"
                    />
                </div>
                <span class="font-mono text-sm tabular-nums text-snitch-ink">
                    {{ row.erLabel }}
                </span>
            </li>
        </ul>
    </div>
    <p
        v-else
        class="text-sm text-snitch-ink/55"
    >
        Not enough engagement data yet.
    </p>
</template>
