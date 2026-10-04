<script setup lang="ts">
import { computed } from 'vue';

type Gap = {
    type: 'pp' | 'x';
    value: number;
    lower?: boolean;
};

const props = defineProps<{
    label: string;
    value: string;
    gap?: Gap | null;
    note?: string | null;
    accent?: boolean;
}>();

const numberFmt = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 });

const gapLabel = computed(() => {
    if (! props.gap) {
        return null;
    }

    if (props.gap.type === 'pp') {
        const sign = props.gap.value > 0 ? '+' : '';

        return `${sign}${numberFmt.format(props.gap.value)} points`;
    }

    if (props.gap.lower) {
        return `${numberFmt.format(props.gap.value)}× lower`;
    }

    return `${numberFmt.format(props.gap.value)}× rivals`;
});

const gapClass = computed(() => {
    if (! props.gap) {
        return '';
    }

    if (props.gap.lower || (props.gap.type === 'pp' && props.gap.value < 0)) {
        return 'snitch-gap-lower rounded px-1.5 py-0.5';
    }

    return 'text-snitch-ink/70';
});
</script>

<template>
    <div class="flex min-h-[5.5rem] flex-col justify-between rounded border border-snitch-ink/10 bg-snitch-lift p-3">
        <p class="text-sm font-medium text-snitch-ink/70">
            {{ label }}
        </p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-2">
            <p
                class="font-mono text-3xl font-semibold tracking-tight tabular-nums leading-none"
                :class="accent ? 'snitch-stat-accent' : 'text-snitch-ink'"
            >
                {{ value }}
            </p>
            <span
                v-if="gapLabel"
                class="text-sm font-medium tabular-nums"
                :class="gapClass"
            >
                {{ gapLabel }}
            </span>
        </div>
        <p
            v-if="note"
            class="mt-2 text-sm leading-snug text-snitch-ink/70"
        >
            {{ note }}
        </p>
    </div>
</template>
