<script setup lang="ts">
import { Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/dashboard/EmptyState.vue';

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
    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="flex items-start justify-between gap-2">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ label }}</div>
            <button
                type="button"
                class="relative rounded p-0.5 text-slate-400 hover:text-slate-700"
                @click="open = !open"
                @blur="open = false"
            >
                <Info class="h-3.5 w-3.5" />
                <div
                    v-if="open"
                    class="absolute right-0 z-20 mt-2 w-56 rounded-lg border border-slate-200 bg-white p-2 text-left text-[11px] text-slate-600 shadow-sm"
                >
                    {{ formula }}
                </div>
            </button>
        </div>
        <p class="mt-1 text-[11px] leading-snug text-slate-400">{{ why }}</p>

        <EmptyState v-if="status !== 'ok' || youLabel == null" :reason="reason" compact class="mt-3" />
        <template v-else>
            <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-900 tabular-nums">
                {{ youLabel }}
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                <span>Peer median: {{ peerLabel }}</span>
                <span
                    v-if="gapLabel"
                    class="rounded-md px-1.5 py-0.5 font-medium tabular-nums"
                    :class="gapClass"
                >
                    {{ gapLabel }}
                </span>
            </div>
        </template>
    </div>
</template>
