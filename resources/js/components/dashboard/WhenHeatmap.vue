<script setup lang="ts">
import { computed } from 'vue';
import EmptyState from '@/components/dashboard/EmptyState.vue';

type Cell = { pi: number | null; n: number; count: number };

const props = defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    days?: string[];
    blocks?: string[];
    cells?: Cell[][];
    ownDots?: { dow: number; block: number }[];
    mode?: 'pi' | 'count';
}>();

const maxCount = computed(() => {
    let max = 1;

    for (const row of props.cells ?? []) {
        for (const cell of row) {
            max = Math.max(max, cell.count);
        }
    }

    return max;
});

function bg(cell: Cell): string {
    if ((props.mode ?? 'pi') === 'count') {
        if (cell.count === 0) {
            return '#f8fafc';
        }

        const t = cell.count / maxCount.value;

        return `color-mix(in oklab, #0f172a ${Math.round(t * 70)}%, #f8fafc)`;
    }

    if (cell.n < 3 || cell.pi == null) {
        return '#f1f5f9';
    }

    const t = Math.min(1, Math.max(0, (cell.pi - 0.5) / 2));

    return `color-mix(in oklab, #0f766e ${Math.round(t * 75)}%, #f8fafc)`;
}

function hasOwn(dow: number, block: number): boolean {
    return (props.ownDots ?? []).some((dot) => dot.dow === dow && dot.block === block);
}
</script>

<template>
    <EmptyState v-if="status === 'empty'" :reason="reason" compact />
    <div v-else>
        <p v-if="reason" class="mb-2 text-[11px] text-slate-500">{{ reason }}</p>
        <div class="grid grid-cols-[2.5rem_repeat(6,minmax(0,1fr))] gap-1 text-[10px]">
            <div />
            <div
                v-for="block in blocks || []"
                :key="block"
                class="truncate text-center font-medium uppercase tracking-wide text-slate-400"
            >
                {{ block }}
            </div>
            <template v-for="(day, dow) in days || []" :key="day">
                <div class="flex items-center font-medium text-slate-500">{{ day }}</div>
                <div
                    v-for="(cell, block) in cells?.[dow] || []"
                    :key="`${day}-${block}`"
                    class="relative flex h-6 items-center justify-center rounded border border-slate-100"
                    :style="{ backgroundColor: bg(cell) }"
                    :title="`${day} ${blocks?.[block]} · n=${cell.n || cell.count}${cell.pi != null ? ` · PI ${cell.pi}` : ''}`"
                >
                    <span
                        v-if="(mode ?? 'pi') === 'pi' && cell.pi != null && cell.n >= 3"
                        class="font-medium tabular-nums"
                        :class="cell.pi >= 1.2 ? 'text-white' : 'text-slate-700'"
                    >
                        {{ cell.pi.toFixed(1) }}
                    </span>
                    <span
                        v-else-if="(mode ?? 'pi') === 'count' && cell.count > 0"
                        class="tabular-nums text-white"
                    >
                        {{ cell.count }}
                    </span>
                    <span
                        v-if="hasOwn(dow, block)"
                        class="absolute right-0.5 top-0.5 h-1.5 w-1.5 rounded-full bg-slate-900"
                    />
                </div>
            </template>
        </div>
    </div>
</template>
