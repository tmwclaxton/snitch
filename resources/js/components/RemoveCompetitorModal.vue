<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LoaderCircle, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { destroy } from '@/actions/App/Http/Controllers/CompetitorController';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    open: boolean;
    account: {
        id: number;
        handle: string;
        display_name: string | null;
    } | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const processing = ref(false);

const displayName = computed(
    () => props.account?.display_name || props.account?.handle || 'this competitor',
);

function setOpen(value: boolean): void {
    if (!value) {
        processing.value = false;
    }

    emit('update:open', value);
}

function confirmRemove(): void {
    if (!props.account || processing.value) {
        return;
    }

    processing.value = true;

    router.delete(destroy.url(props.account.id), {
        onFinish: () => {
            processing.value = false;
        },
        onSuccess: () => {
            setOpen(false);
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            :show-close-button="false"
            class="snitch-modal-panel gap-0 overflow-hidden border-0 p-0 shadow-none sm:max-w-md"
        >
            <div class="snitch-doc relative space-y-6 p-6">
                <span class="snitch-tape left-5 -top-2" aria-hidden="true" />

                <DialogHeader class="relative z-10 space-y-3 text-left">
                    <DialogTitle class="snitch-display flex items-center gap-2 text-xl text-snitch-ink">
                        <Trash2 class="size-5 shrink-0 text-snitch-ink/55" aria-hidden="true" />
                        Remove this competitor?
                    </DialogTitle>
                    <DialogDescription class="text-sm text-snitch-ink/65">
                        Stop tracking
                        <span class="font-medium text-snitch-ink">{{ displayName }}</span>
                        <template v-if="account">
                            (<span class="snitch-annotation text-base">@{{ account.handle }}</span>).
                        </template>
                        Their posts leave your feed. You can add them again later.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="relative z-10 gap-2 sm:justify-start">
                    <DialogClose as-child>
                        <button type="button" class="snitch-btn snitch-btn-ghost">
                            <X class="relative z-10 size-3.5 shrink-0" aria-hidden="true" />
                            <span class="relative z-10">Cancel</span>
                        </button>
                    </DialogClose>

                    <button
                        type="button"
                        class="snitch-btn"
                        :disabled="!account || processing"
                        data-test="confirm-remove-competitor-button"
                        @click="confirmRemove"
                    >
                        <LoaderCircle
                            v-if="processing"
                            class="relative z-10 size-3.5 shrink-0 animate-spin"
                            aria-hidden="true"
                        />
                        <Trash2
                            v-else
                            class="relative z-10 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="relative z-10">
                            {{ processing ? 'Removing…' : 'Remove' }}
                        </span>
                    </button>
                </DialogFooter>
            </div>
        </DialogContent>
    </Dialog>
</template>
