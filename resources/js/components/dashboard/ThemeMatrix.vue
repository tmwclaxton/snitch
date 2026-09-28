<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { show as competitorShow } from '@/actions/App/Http/Controllers/CompetitorController';
import EmptyState from '@/components/dashboard/EmptyState.vue';

type Cell = { handle: string; share: number; pi: number | null; n: number };
type Row = { theme: string; theme_key: string; cells: Cell[] };
type Account = { id?: number | null; handle: string; is_own_account: boolean };
type Gap = { theme: string; peer_pi: number; n: number };

const props = withDefaults(
    defineProps<{
        status: 'ok' | 'insufficient' | 'empty';
        reason?: string | null;
        accounts?: Account[];
        matrix?: Row[];
        gaps?: Gap[];
        maxThemes?: number;
    }>(),
    { maxThemes: 5 },
);

const visibleMatrix = computed(() => {
    const rows = props.matrix ?? [];

    if (rows.length <= props.maxThemes) {
        return rows;
    }

    // Prefer themes with the most posts; keep "other" last if present.
    const ranked = [...rows].sort((a, b) => {
        const aN = a.cells.reduce((sum, cell) => sum + cell.n, 0);
        const bN = b.cells.reduce((sum, cell) => sum + cell.n, 0);

        if (a.theme_key === 'other') {
            return 1;
        }

        if (b.theme_key === 'other') {
            return -1;
        }

        return bN - aN;
    });

    return ranked.slice(0, props.maxThemes);
});

const otherHeavy = computed(() => {
    const other = (props.matrix ?? []).find((row) => row.theme_key === 'other');
    const own = other?.cells.find((cell) => {
        const account = (props.accounts ?? []).find((a) => a.handle === cell.handle);

        return account?.is_own_account;
    });

    return own != null && own.share >= 50;
});

const hasGaps = computed(() => (props.gaps ?? []).length > 0);

function cellBg(pi: number | null, n: number): string {
    if (n < 3 || pi == null) {
        return '#f8fafc';
    }

    const t = Math.min(1, Math.max(0, (pi - 0.5) / 2));

    return `color-mix(in oklab, #0f766e ${Math.round(t * 70)}%, #f8fafc)`;
}

function accountHeading(account: Account): string {
    return account.is_own_account ? 'You' : `@${account.handle}`;
}
</script>

<template>
    <EmptyState v-if="status !== 'ok'" :reason="reason" compact />
    <div
        v-else
        class="grid gap-3"
        :class="hasGaps ? 'lg:grid-cols-[minmax(0,1fr)_11rem]' : ''"
    >
        <div class="min-w-0 overflow-x-hidden">
            <table class="w-full table-fixed text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs text-slate-500">
                        <th class="w-[18%] py-0.5 pr-1.5 font-medium uppercase">Theme</th>
                        <th
                            v-for="account in accounts || []"
                            :key="account.handle"
                            class="break-words px-0.5 py-0.5 font-medium normal-case leading-tight"
                        >
                            <Link
                                v-if="account.id"
                                :href="competitorShow.url(account.id)"
                                class="hover:underline"
                            >
                                {{ accountHeading(account) }}
                            </Link>
                            <template v-else>
                                {{ accountHeading(account) }}
                            </template>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in visibleMatrix" :key="row.theme_key" class="border-b border-slate-100">
                        <td class="break-words py-0.5 pr-1.5 font-medium leading-snug text-slate-700">{{ row.theme }}</td>
                        <td
                            v-for="cell in row.cells"
                            :key="cell.handle"
                            class="px-0.5 py-0.5 tabular-nums"
                            :style="{ backgroundColor: cellBg(cell.pi, cell.n) }"
                            :title="`n=${cell.n}${cell.pi != null ? ` · ${cell.pi}× usual` : ''}`"
                        >
                            {{ cell.n < 1 ? '-' : `${cell.share.toFixed(0)}%` }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="otherHeavy" class="mt-1 text-xs text-slate-400">
                "Other" is high for You - theme tags are still coarse.
            </p>
        </div>
        <div v-if="hasGaps">
            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Gaps you skip</p>
            <ul class="space-y-1">
                <li
                    v-for="gap in gaps"
                    :key="gap.theme"
                    class="rounded border border-slate-100 bg-slate-50 px-1.5 py-0.5 text-sm text-slate-700"
                >
                    <span class="font-medium">{{ gap.theme }}</span>
                    <span class="text-slate-500"> · peers {{ gap.peer_pi.toFixed(1) }}x · n={{ gap.n }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
