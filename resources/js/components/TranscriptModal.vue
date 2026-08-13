<script setup lang="ts">
import { Check, ClipboardCopy, ScrollText, X } from '@lucide/vue';
import { computed, ref } from 'vue';
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
    warning?: string | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const toast = useToastStore();
const justCopied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | null = null;

const transcriptNoticePattern =
    /\n\n\[(Output limit reached|Transcript may be incomplete)[^\]]+\]\s*$/s;

const transcriptWarning = computed(() => {
    const explicit = props.warning?.trim();

    if (explicit) {
        return explicit;
    }

    const match = props.transcript.match(transcriptNoticePattern);

    return match ? match[0].trim() : null;
});

const displayTranscript = computed(() => formatTranscriptForDisplay(props.transcript));

function formatTranscriptForDisplay(text: string): string {
    const trimmed = text.trim();

    if (!trimmed) {
        return '';
    }

    const body = trimmed.replace(transcriptNoticePattern, '').trim();

    return body
        .split(/\n{2,}/)
        .map((block) => block.replace(/\n(?!\n)/g, ' ').replace(/\s+/g, ' ').trim())
        .filter(Boolean)
        .join('\n\n');
}

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
            class="snitch-modal-panel flex w-full max-w-[min(100%-2rem,56rem)] flex-col gap-0 overflow-hidden border-0 p-0 shadow-none sm:max-w-4xl"
        >
            <div class="snitch-doc relative flex min-h-0 w-full max-h-[min(92vh,52rem)] flex-col p-6 sm:p-7">
                <span class="snitch-tape left-6 -top-2" aria-hidden="true" />

                <div class="relative z-10 flex w-full min-w-0 items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
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

                <p
                    v-if="transcriptWarning"
                    class="relative z-10 mt-4 w-full rounded-sm border border-snitch-spot/35 bg-snitch-spot/10 px-3 py-2 text-sm text-snitch-ink/80"
                    data-test="transcript-warning"
                >
                    {{ transcriptWarning }}
                </p>

                <div class="relative z-10 mt-5 min-h-0 w-full min-w-0 flex-1">
                    <div
                        class="snitch-scrap relative min-h-[12rem] w-full min-w-0 max-h-[min(70vh,40rem)] overflow-y-auto overflow-x-hidden p-4 sm:p-5"
                    >
                        <p
                            v-if="displayTranscript"
                            class="relative z-10 block w-full min-w-0 max-w-none break-words whitespace-pre-wrap text-sm leading-relaxed text-snitch-ink/85 sm:text-base"
                            data-test="transcript-body"
                        >
                            {{ displayTranscript }}
                        </p>
                        <p
                            v-else
                            class="relative z-10 text-sm italic text-snitch-ink/55"
                        >
                            No spoken words captured for this reel.
                        </p>
                    </div>
                </div>

                <div class="relative z-10 mt-5 flex w-full flex-wrap items-center gap-2">
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
