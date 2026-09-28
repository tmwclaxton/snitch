<script setup lang="ts">
import EmptyState from '@/components/dashboard/EmptyState.vue';
import { followDashboardLink } from '@/lib/dashboardAnchors';

type Item = { text: string; links_to: string; n: number };

defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    items?: Item[];
    peerOnly?: boolean;
}>();
</script>

<template>
    <div>
        <p v-if="reason && (status !== 'ok' || peerOnly)" class="mb-1.5 text-xs text-slate-500">{{ reason }}</p>
        <EmptyState v-if="!(items || []).length" :reason="reason || 'No actions yet.'" compact />
        <ol v-else class="space-y-1">
            <li
                v-for="(item, index) in items"
                :key="index"
                class="flex items-start gap-2 rounded border border-slate-100 bg-slate-50/60 px-1.5 py-1 text-sm text-slate-700"
            >
                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-semibold text-white">
                    {{ index + 1 }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="leading-snug">{{ item.text }}</p>
                    <button
                        type="button"
                        class="mt-0.5 text-xs font-medium text-slate-500 underline-offset-2 hover:underline"
                        @click="followDashboardLink(item.links_to)"
                    >
                        see why →
                    </button>
                </div>
            </li>
        </ol>
    </div>
</template>
