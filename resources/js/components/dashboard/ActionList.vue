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
        <p v-if="reason && (status !== 'ok' || peerOnly)" class="mb-1.5 text-xs text-snitch-ink/55">{{ reason }}</p>
        <EmptyState v-if="!(items || []).length" :reason="reason || 'No actions yet.'" compact />
        <ol v-else class="space-y-1">
            <li
                v-for="(item, index) in items"
                :key="index"
                class="flex items-start gap-2 rounded border border-snitch-ink/10 bg-snitch-fog/40 px-1.5 py-1 text-sm text-snitch-ink/80"
            >
                <span class="snitch-format-tag flex h-4 w-4 shrink-0 items-center justify-center rounded-full !px-0 text-xs font-semibold">
                    {{ index + 1 }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="leading-snug">{{ item.text }}</p>
                    <button
                        type="button"
                        class="mt-0.5 text-xs font-medium text-snitch-ink/55 underline-offset-2 hover:underline"
                        @click="followDashboardLink(item.links_to)"
                    >
                        see why →
                    </button>
                </div>
            </li>
        </ol>
    </div>
</template>
