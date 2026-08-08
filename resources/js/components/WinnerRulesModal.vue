<script setup lang="ts">
import { SlidersHorizontal, X } from '@lucide/vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import WinnerRulesForm from '@/components/WinnerRulesForm.vue';
import type {
    WinnerRuleFormData,
    WinnerRulePreset,
} from '@/components/WinnerRulesForm.vue';

defineProps<{
    open: boolean;
    rule: WinnerRuleFormData;
    presets: Record<string, WinnerRulePreset>;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

function setOpen(value: boolean): void {
    emit('update:open', value);
}
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            :show-close-button="false"
            class="snitch-modal-panel gap-0 overflow-hidden border-0 p-0 shadow-none sm:max-w-xl"
        >
            <div class="snitch-doc relative max-h-[min(90vh,40rem)] overflow-y-auto p-6 sm:p-7">
                <span class="snitch-tape left-6 -top-2" aria-hidden="true" />

                <div class="relative z-10 flex items-start justify-between gap-4">
                    <div>
                        <DialogTitle class="snitch-display flex items-center gap-2 text-2xl text-snitch-ink">
                            <SlidersHorizontal class="size-5 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                            Winner rules
                        </DialogTitle>
                        <DialogDescription class="mt-1.5 text-sm text-snitch-ink/65">
                            Set the bar for the tear sheet. Escape or close to dismiss.
                        </DialogDescription>
                    </div>
                    <DialogClose
                        class="snitch-btn snitch-btn-ghost shrink-0 px-2.5 py-1.5 text-sm"
                        aria-label="Close rules"
                    >
                        <X class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                        <span class="relative z-10">Close</span>
                    </DialogClose>
                </div>

                <div class="relative z-10 mt-5">
                    <WinnerRulesForm
                        :rule="rule"
                        :presets="presets"
                        @saved="setOpen(false)"
                    />
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
