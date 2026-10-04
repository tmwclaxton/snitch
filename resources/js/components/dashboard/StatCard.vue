<script setup lang="ts">
import { Info } from '@lucide/vue';
import { computed, ref } from 'vue';

type Gap = {
    type: 'pp' | 'x';
    value: number;
    lower?: boolean;
};

const props = defineProps<{
    label: string;
    why: string;
    formula: string;
    status: string;
    reason?: string | null;
    you?: number | null;
    youDisplay?: number | string | null;
    peerMedian?: number | null;
    gap?: Gap | null;
    unit: string;
}>();

const open = ref(false);

const numberFmt = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 });

const youLabel = computed(() => {
    if (props.youDisplay != null && props.label.startsWith('Followers')) {
        return typeof props.youDisplay === 'number'
            ? new Intl.NumberFormat('en-GB').format(props.youDisplay)
            : String(props.youDisplay);
    }

    if (props.you == null) {
        return '-';
    }

    if (props.unit === 'pct') {
        return `${numberFmt.format(props.you)}%`;
    }

    return numberFmt.format(props.you);
});

const peerLabel = computed(() => {
    if (props.peerMedian == null) {
        return '-';
    }

    if (props.unit === 'pct') {
        return `${numberFmt.format(props.peerMedian)}%`;
    }

    return numberFmt.format(props.peerMedian);
});

const gapLabel = computed(() => {
    if (!props.gap || props.you == null) {
        return null;
    }

    if (props.gap.type === 'pp') {
        const sign = props.gap.value > 0 ? '+' : '';

        return `${sign}${numberFmt.format(props.gap.value)} points`;
    }

    if (props.gap.lower || props.gap.value < 1) {
        return `${numberFmt.format(1 / Math.max(props.gap.value, 0.01))}× lower`;
    }

    return `${numberFmt.format(props.gap.value)}× rivals`;
});

const gapClass = computed(() => {
    if (!props.gap) {
        return 'bg-snitch-ink/5 text-snitch-ink/65';
    }

    if (props.gap.type === 'pp') {
        return props.gap.value >= 0
            ? 'snitch-highlight'
            : 'bg-snitch-alert text-snitch-paper';
    }

    return props.gap.lower || props.gap.value < 1
        ? 'bg-snitch-alert text-snitch-paper'
        : 'snitch-highlight';
});

const growthHint = computed(() => {
    if (! props.label.startsWith('Followers')) {
        return null;
    }

    if (props.you == null) {
        return props.reason || 'growth from next week';
    }

    return `${numberFmt.format(props.you)}% 30d`;
});
</script>

<template>
    <div class="min-w-0 border-r border-snitch-ink/10 px-1 py-0.5 last:border-r-0">
        <div class="flex items-center justify-between gap-1">
            <div
                class="min-w-0 font-mono text-sm font-medium uppercase leading-tight tracking-wide text-snitch-ink/55"
                :title="label"
            >
                {{ label }}
            </div>
            <button
                type="button"
                class="relative rounded p-0.5 text-snitch-ink/40 hover:text-snitch-ink"
                :aria-label="'About ' + label"
                :title="reason || why"
                @click="open = !open"
                @blur="open = false"
            >
                <Info class="h-3 w-3" />
                <div
                    v-if="open"
                    class="absolute right-0 z-20 mt-2 w-52 rounded-lg border border-snitch-ink/10 bg-snitch-lift p-2 text-left text-sm text-snitch-ink/70 shadow-sm"
                >
                    <p class="mb-1 text-snitch-ink">{{ why }}</p>
                    <p class="text-snitch-ink/55">{{ formula }}</p>
                    <p v-if="reason" class="mt-1 text-snitch-ink/45">{{ reason }}</p>
                </div>
            </button>
        </div>

        <div
            class="mt-0.5 font-mono text-xl font-semibold tracking-tight text-snitch-ink tabular-nums leading-none"
            :title="youLabel === '-' ? (reason || undefined) : undefined"
        >
            {{ youLabel }}
        </div>
        <div class="mt-0.5 flex flex-wrap items-center gap-1 font-mono text-sm leading-tight text-snitch-ink/55">
            <span v-if="growthHint">{{ growthHint }}</span>
            <span v-else>Rivals' average {{ peerLabel }}</span>
            <span
                v-if="gapLabel"
                class="rounded px-1 py-px font-medium tabular-nums"
                :class="gapClass"
            >
                {{ gapLabel }}
            </span>
        </div>
    </div>
</template>
