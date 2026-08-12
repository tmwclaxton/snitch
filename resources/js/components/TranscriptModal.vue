<script setup lang="ts">
import { Check, ClipboardCopy, ScrollText, X } from '@lucide/vue';
import { ref } from 'vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useToastStore } from '@/stores/toastStore';

const props = defineProps<{
    open: boolean;
    transcript: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const toast = useToastStore();
const justCopied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | null = null;

function setOpen(value: boolean): void {
    emit('update:open', value);
}

async function copyTranscript(): Promise<void> {
    const text = props.transcript.trim();

    if (!text) {
        return;
    }

    try {
        await navigator.clipboard.writeText(text);
        toast.success('Transcript copied to clipboard.');
    } catch {
        toast.error('Could not access the clipboard.');

        return;
    }

    justCopied.value = true;

    if (copiedTimer) {
        clearTimeout(copiedTimer);
    }

    copiedTimer = setTimeout(() => {
        justCopied.value = false;
        copiedTimer = null;
    }, 1800);
}
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            :show-close-button="false"
            class="snitch-modal-panel w-full gap-0 overflow-hidden border-0 p-0 shadow-none sm:max-w-4xl"
        >
            <div class="snitch-doc relative flex w-full max-h-[min(92vh,52rem)] flex-col p-6 sm:p-7">
                <span class="snitch-tape left-6 -top-2" aria-hidden="true" />

                <div class="relative z-10 flex items-start justify-between gap-4">
                    <div>
                        <DialogTitle class="snitch-display flex items-center gap-2 text-2xl text-snitch-ink">
                            <ScrollText class="size-5 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                            Transcript
                        </DialogTitle>
                        <DialogDescription class="mt-1.5 text-sm text-snitch-ink/65">
                            Verbatim spoken words from the reel. Escape or close to dismiss.
                        </DialogDescription>
                    </div>
                    <DialogClose
                        class="snitch-btn snitch-btn-ghost shrink-0 px-2.5 py-1.5 text-sm"
                        aria-label="Close transcript"
                    >
                        <X class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                        <span class="relative z-10">Close</span>
                    </DialogClose>
                </div>

                <div class="relative z-10 mt-5 min-h-0 w-full flex-1">
                    <div
                        class="snitch-scrap relative w-full max-h-[min(70vh,40rem)] overflow-y-auto p-4 sm:p-5"
                    >
                        <p
                            v-if="transcript.trim()"
                            class="relative z-10 w-full whitespace-pre-wrap text-sm leading-relaxed text-snitch-ink/85 sm:text-base"
                        >
                            {{ transcript }}
                        </p>
                        <p
                            v-else
                            class="relative z-10 text-sm italic text-snitch-ink/55"
                        >
                            No spoken words captured for this reel.
                        </p>
                    </div>
                </div>

                <div class="relative z-10 mt-5 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="snitch-btn"
                        :disabled="!transcript.trim()"
                        data-test="copy-transcript-button"
                        @click="copyTranscript"
                    >
                        <Check
                            v-if="justCopied"
                            class="relative z-10 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <ClipboardCopy
                            v-else
                            class="relative z-10 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="relative z-10">
                            {{ justCopied ? 'Copied' : 'Copy transcript' }}
                        </span>
                    </button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
