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
}>();

const numberFmt = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 });

const highlightValue = computed(() => props.value.trim().length <= 8);

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

    if (props.gap.type === 'pp') {
        return props.gap.value >= 0
            ? 'snitch-highlight'
            : 'bg-snitch-alert text-snitch-paper';
    }

    return props.gap.lower
        ? 'bg-snitch-alert text-snitch-paper'
        : 'snitch-highlight';
});
</script>

<template>
    <div class="flex min-h-[5.5rem] flex-col justify-between rounded border border-snitch-ink/10 bg-snitch-lift p-3">
        <p class="text-sm font-medium text-snitch-ink/55">
            {{ label }}
        </p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-2">
            <p class="font-display text-3xl font-semibold tracking-tight text-snitch-ink tabular-nums leading-none">
                <span :class="highlightValue ? 'snitch-highlight' : ''">{{ value }}</span>
            </p>
            <span
                v-if="gapLabel"
                class="rounded px-1.5 py-0.5 text-sm font-medium tabular-nums"
                :class="gapClass"
            >
                {{ gapLabel }}
            </span>
        </div>
        <p
            v-if="note"
            class="mt-2 text-sm leading-snug text-snitch-ink/55"
        >
            {{ note }}
        </p>
    </div>
</template>
