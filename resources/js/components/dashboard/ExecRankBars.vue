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
    const withEr = props.rows
        .filter((row) => typeof row.er === 'number' && Number.isFinite(row.er))
        .map((row) => ({
            ...row,
            er: Number(row.er),
            hasData: true as const,
        }))
        .sort((a, b) => b.er - a.er);

    const withoutEr = props.rows
        .filter((row) => typeof row.er !== 'number' || ! Number.isFinite(row.er))
        .map((row) => ({
            ...row,
            er: null as number | null,
            hasData: false as const,
        }));

    const max = Math.max(0.01, ...withEr.map((row) => row.er));

    return [...withEr, ...withoutEr].map((row) => ({
        handle: row.handle,
        is_own_account: row.is_own_account,
        hasData: row.hasData,
        width: row.hasData ? `${Math.max(8, (Number(row.er) / max) * 100)}%` : '0%',
        label: row.is_own_account ? 'You' : `@${row.handle}`,
        erLabel: row.hasData ? `${Number(row.er).toFixed(1)}%` : 'no data yet',
    }));
});
</script>

<template>
    <div
        v-if="ranked.length"
        class="flex h-full min-h-0 flex-col"
    >
        <p class="text-sm font-medium uppercase tracking-wide text-snitch-ink/55">
            Engagement rank
        </p>
        <ul class="mt-3 flex flex-1 flex-col justify-center gap-2">
            <li
                v-for="row in ranked"
                :key="row.handle"
                class="grid grid-cols-[minmax(8rem,11rem)_1fr_auto] items-center gap-2"
            >
                <span
                    class="whitespace-nowrap text-sm font-medium"
                    :class="row.is_own_account
                        ? 'font-semibold text-snitch-ink'
                        : row.hasData
                            ? 'text-snitch-ink'
                            : 'text-snitch-ink/45'"
                >
                    {{ row.label }}
                </span>
                <div class="h-3 overflow-hidden rounded-sm bg-snitch-ink/8">
                    <div
                        v-if="row.hasData"
                        class="h-full rounded-sm"
                        :class="row.is_own_account ? 'bg-snitch-spot' : 'bg-snitch-ink'"
                        :style="{ width: row.width }"
                    />
                </div>
                <span
                    class="font-mono text-sm tabular-nums"
                    :class="row.hasData ? 'text-snitch-ink' : 'text-snitch-ink/45'"
                >
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
