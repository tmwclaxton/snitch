<script setup lang="ts">
import EmptyState from '@/components/dashboard/EmptyState.vue';

type Item = { text: string; links_to: string; n: number };

defineProps<{
    status: 'ok' | 'insufficient' | 'empty';
    reason?: string | null;
    items?: Item[];
    peerOnly?: boolean;
}>();

function scrollTo(id: string): void {
    const el = document.getElementById(id);

    if (!el) {
        return;
    }

    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    el.classList.add('ring-2', 'ring-slate-900', 'ring-offset-2');
    window.setTimeout(() => {
        el.classList.remove('ring-2', 'ring-slate-900', 'ring-offset-2');
    }, 1600);
}
</script>

<template>
    <div>
        <p v-if="reason && (status !== 'ok' || peerOnly)" class="mb-2 text-[11px] text-slate-500">{{ reason }}</p>
        <EmptyState v-if="!(items || []).length" :reason="reason || 'No actions yet.'" compact />
        <ol v-else class="space-y-1.5">
            <li
                v-for="(item, index) in items"
                :key="index"
                class="flex items-start gap-2 rounded border border-slate-100 bg-slate-50/60 px-2 py-1.5 text-[11px] text-slate-700"
            >
                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-slate-900 text-[9px] font-semibold text-white">
                    {{ index + 1 }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="leading-snug">{{ item.text }}</p>
                    <button
                        type="button"
                        class="mt-0.5 text-[10px] font-medium text-slate-500 underline-offset-2 hover:underline"
                        @click="scrollTo(item.links_to)"
                    >
                        see why →
                    </button>
                </div>
            </li>
        </ol>
    </div>
</template>
