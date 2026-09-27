<script setup lang="ts">
import EmptyState from '@/components/dashboard/EmptyState.vue';

type Cell = { handle: string; share: number; pi: number | null; n: number };
type Row = { theme: string; theme_key: string; cells: Cell[] };
type Account = { handle: string; is_own_account: boolean };
type Gap = { theme: string; peer_pi: number; n: number };

defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    accounts?: Account[];
    matrix?: Row[];
    gaps?: Gap[];
}>();

function cellBg(pi: number | null, n: number): string {
    if (n < 3 || pi == null) {
        return '#f8fafc';
    }

    const t = Math.min(1, Math.max(0, (pi - 0.5) / 2));

    return `color-mix(in oklab, #0f766e ${Math.round(t * 70)}%, #f8fafc)`;
}
</script>

<template>
    <EmptyState v-if="status !== 'ok'" :reason="reason" compact />
    <div v-else class="grid gap-3 lg:grid-cols-[1fr_12rem]">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-[11px]">
                <thead>
                    <tr class="border-b border-slate-200 text-[10px] uppercase text-slate-500">
                        <th class="py-1 pr-2 font-medium">Theme</th>
                        <th
                            v-for="account in accounts || []"
                            :key="account.handle"
                            class="px-1 py-1 font-medium"
                        >
                            {{ account.is_own_account ? 'You' : `@${account.handle}` }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in matrix || []" :key="row.theme_key" class="border-b border-slate-100">
                        <td class="py-1 pr-2 font-medium text-slate-700">{{ row.theme }}</td>
                        <td
                            v-for="cell in row.cells"
                            :key="cell.handle"
                            class="px-1 py-1 tabular-nums"
                            :style="{ backgroundColor: cellBg(cell.pi, cell.n) }"
                            :title="`n=${cell.n}${cell.pi != null ? ` · PI ${cell.pi}` : ''}`"
                        >
                            {{ cell.n < 1 ? '—' : `${cell.share.toFixed(0)}%` }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div>
            <p class="mb-1 text-[10px] font-medium uppercase tracking-wide text-slate-500">Gaps you skip</p>
            <EmptyState v-if="!(gaps || []).length" reason="No theme gaps yet" compact />
            <ul v-else class="space-y-1">
                <li
                    v-for="gap in gaps"
                    :key="gap.theme"
                    class="rounded border border-slate-100 bg-slate-50 px-2 py-1 text-[11px] text-slate-700"
                >
                    <span class="font-medium">{{ gap.theme }}</span>
                    <span class="text-slate-500"> · peers {{ gap.peer_pi.toFixed(1) }}× · n={{ gap.n }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
