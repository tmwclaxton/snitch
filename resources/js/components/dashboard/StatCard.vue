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

    if (props.status !== 'ok' || props.you == null) {
        return null;
    }

    if (props.unit === 'pct') {
        return `${numberFmt.format(props.you)}%`;
    }

    return numberFmt.format(props.you);
});

const peerLabel = computed(() => {
    if (props.peerMedian == null) {
        return '—';
    }

    if (props.unit === 'pct') {
        return `${numberFmt.format(props.peerMedian)}%`;
    }

    return numberFmt.format(props.peerMedian);
});

const gapLabel = computed(() => {
    if (!props.gap) {
        return null;
    }

    if (props.gap.type === 'pp') {
        const sign = props.gap.value > 0 ? '+' : '';

        return `${sign}${numberFmt.format(props.gap.value)} pp`;
    }

    if (props.gap.lower || props.gap.value < 1) {
        return `${numberFmt.format(1 / Math.max(props.gap.value, 0.01))}× lower`;
    }

    return `${numberFmt.format(props.gap.value)}× peers`;
});

const gapClass = computed(() => {
    if (!props.gap) {
        return 'bg-slate-100 text-slate-600';
    }

    if (props.gap.type === 'pp') {
        return props.gap.value >= 0 ? 'bg-green-50 text-green-700' : 'bg-rose-50 text-rose-700';
    }

    return props.gap.lower || props.gap.value < 1
        ? 'bg-rose-50 text-rose-700'
        : 'bg-green-50 text-green-700';
});
</script>

<template>
    <div class="rounded-md border border-slate-200 bg-white px-2 py-1.5">
        <div class="flex items-center justify-between gap-1">
            <div class="truncate text-[9px] font-medium uppercase tracking-wide text-slate-500">{{ label }}</div>
            <button
                type="button"
                class="relative rounded p-0.5 text-slate-400 hover:text-slate-700"
                :aria-label="'About ' + label"
                @click="open = !open"
                @blur="open = false"
            >
                <Info class="h-3 w-3" />
                <div
                    v-if="open"
                    class="absolute right-0 z-20 mt-2 w-52 rounded-lg border border-slate-200 bg-white p-2 text-left text-[11px] text-slate-600 shadow-sm"
                >
                    <p class="mb-1 text-slate-700">{{ why }}</p>
                    <p class="text-slate-500">{{ formula }}</p>
                </div>
            </button>
        </div>

        <template v-if="youLabel != null">
            <div class="mt-0.5 text-base font-semibold tracking-tight text-slate-900 tabular-nums leading-none">
                {{ youLabel }}
            </div>
            <div class="mt-0.5 flex flex-wrap items-center gap-1 text-[10px] leading-none text-slate-500">
                <span>Peer {{ peerLabel }}</span>
                <span
                    v-if="gapLabel"
                    class="rounded px-1 py-0.5 font-medium tabular-nums"
                    :class="gapClass"
                >
                    {{ gapLabel }}
                </span>
            </div>
        </template>
        <div v-else class="mt-1 text-[10px] leading-snug text-slate-400">
            {{ reason || '—' }}
        </div>
    </div>
</template>
