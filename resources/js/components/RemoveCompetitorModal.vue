<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LoaderCircle, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { batchDestroy, destroy } from '@/actions/App/Http/Controllers/CompetitorController';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type RemovableAccount = {
    id: number;
    handle: string;
    display_name: string | null;
};

const props = defineProps<{
    open: boolean;
    account?: RemovableAccount | null;
    accounts?: RemovableAccount[];
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    removed: [];
}>();

const processing = ref(false);

const targets = computed(() => {
    if (props.accounts && props.accounts.length > 0) {
        return props.accounts;
    }

    return props.account ? [props.account] : [];
});

const isBatch = computed(() => targets.value.length > 1);

const displayName = computed(() => {
    const first = targets.value[0];

    if (!first) {
        return 'this snitch';
    }

    return first.display_name || first.handle || 'this snitch';
});

function setOpen(value: boolean): void {
    if (!value) {
        processing.value = false;
    }

    emit('update:open', value);
}

function confirmRemove(): void {
    if (targets.value.length === 0 || processing.value) {
        return;
    }

    processing.value = true;

    const onFinish = (): void => {
        processing.value = false;
    };

    const onSuccess = (): void => {
        emit('removed');
        setOpen(false);
    };

    if (isBatch.value || (props.accounts && props.accounts.length > 0)) {
        router.post(
            batchDestroy.url(),
            { ids: targets.value.map((item) => item.id) },
            { onFinish, onSuccess },
        );

        return;
    }

    const single = targets.value[0];

    if (!single) {
        processing.value = false;

        return;
    }

    router.delete(destroy.url(single.id), {
        onFinish,
        onSuccess,
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
                        <template v-if="isBatch">
                            Remove {{ targets.length }} snitches?
                        </template>
                        <template v-else>
                            Remove this snitch?
                        </template>
                    </DialogTitle>
                    <DialogDescription class="text-sm text-snitch-ink/65">
                        <template v-if="isBatch">
                            Stop tracking these
                            <span class="font-medium text-snitch-ink">{{ targets.length }}</span>
                            accounts. Their posts leave your feed. You can add them again later.
                        </template>
                        <template v-else>
                            Stop tracking
                            <span class="font-medium text-snitch-ink">{{ displayName }}</span>
                            <template v-if="targets[0]">
                                (<span class="snitch-annotation text-base">@{{ targets[0].handle }}</span>).
                            </template>
                            Their posts leave your feed. You can add them again later.
                        </template>
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
                        :disabled="targets.length === 0 || processing"
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
                            {{ processing ? 'Removing…' : (isBatch ? `Remove ${targets.length}` : 'Remove') }}
                        </span>
                    </button>
                </DialogFooter>
            </div>
        </DialogContent>
    </Dialog>
</template>
